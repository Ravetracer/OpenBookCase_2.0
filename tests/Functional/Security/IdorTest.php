<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ApiApplication;
use App\Entity\Message;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Enums\ApiClientType;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WishlistItemFactory;
use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

/**
 * Cross-resource / cross-user access on the website. Editing any bookcase is
 * crowdsourced by design, but a child resource must only be reachable under its
 * own parent's URL, and everything user-private (wishes, watches, ratings,
 * inbox, API applications and their secrets, verification links) must only
 * ever be read or changed by its owner.
 */
final class IdorTest extends FunctionalTestCase
{
    use SecurityTestTrait;

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function childUnderWrongParent(): iterable
    {
        yield 'image alt' => ['POST', '/api/bookcase/{other}/image/{img}/alt', ['altText' => 'pwned']];
        yield 'image rotate' => ['POST', '/api/bookcase/{other}/image/{img}/rotate', ['direction' => 'cw']];
        yield 'image delete' => ['DELETE', '/api/bookcase/{other}/image/{img}', []];
        yield 'wish drop' => ['POST', '/api/bookcase/{other}/wishlist/{wish}/status', ['action' => 'drop']];
        yield 'wish notfound' => ['POST', '/api/bookcase/{other}/wishlist/{wish}/status', ['action' => 'notfound']];
        yield 'wish delete' => ['DELETE', '/api/bookcase/{other}/wishlist/{wish}', []];
    }

    #[DataProvider('childUnderWrongParent')]
    public function testChildResourceIsNotReachableUnderAnotherBookcase(string $method, string $path, array $params): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $this->loginAsUser();
        $before = $this->dbSnapshot();

        $this->client->request($method, $url, $params);

        $this->assertResponseStatusCodeSame(404, "$method $path");
        $this->assertDbUnchanged($before, "$method $path");
    }

    public function testOnlyTheRequesterCanReportADroppedWishAsNotFound(): void
    {
        $ids = $this->seedWorld();
        $wish = WishlistItemFactory::new()->dropped()->create([
            'bookcase' => $this->em()->getRepository(\App\Entity\Bookcase::class)->find($ids['{bc}']),
            'user' => UserFactory::createOne(),
            'droppedBy' => UserFactory::createOne(),
        ]);
        $this->loginAsUser();
        $before = $this->dbSnapshot();

        $this->client->request('POST', '/api/bookcase/' . $ids['{bc}'] . '/wishlist/' . $wish->id . '/status', ['action' => 'notfound']);

        $this->assertResponseStatusCodeSame(403);
        $this->assertDbUnchanged($before, 'notfound by a non-requester');
    }

    public function testUnwatchingOnlyRemovesTheCallersOwnWatch(): void
    {
        $ids = $this->seedWorld();
        $this->loginAsUser();

        $this->client->request('DELETE', '/api/bookcase/' . $ids['{bc}'] . '/watch');
        $this->assertResponseIsSuccessful();

        $this->assertSame(1, $this->em()->getRepository(WatchlistItem::class)->count([]), "the victim's watch must survive");
    }

    public function testRatingOnlyWritesTheCallersOwnRating(): void
    {
        $ids = $this->seedWorld();
        $intruder = $this->loginAsUser();

        $this->client->request('POST', '/api/bookcase/' . $ids['{bc}'] . '/rating', ['value' => 1]);
        $this->assertResponseIsSuccessful();

        $ratings = $this->em()->getRepository(Rating::class);
        $this->assertSame(5, $ratings->findOneBy(['user' => $ids['{user}']])?->value, "the victim's rating must be untouched");
        $this->assertSame(1, $ratings->findOneBy(['user' => (string) $intruder->id])?->value);
    }

    public function testInboxNeitherShowsNorMarksReadAnotherUsersMessages(): void
    {
        $ids = $this->seedWorld();
        $this->loginAsUser();

        $this->client->request('GET', '/messages');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('Victim private message body', (string) $this->client->getResponse()->getContent());

        $this->em()->clear();
        $message = $this->em()->getRepository(Message::class)->findOneBy(['recipient' => $ids['{user}']]);
        $this->assertNull($message->readAt, "opening my inbox must not mark someone else's messages read");
    }

    public function testProfileWishlistShowsOnlyOwnWishes(): void
    {
        $this->seedWorld();
        $mine = WishlistItemFactory::createOne(['user' => $this->loginAsUser(), 'title' => 'My own wish']);
        $victimTitles = array_map(
            static fn ($w) => $w->title,
            $this->em()->getRepository(\App\Entity\WishlistItem::class)->findAll(),
        );

        $this->client->request('GET', '/profile/wishlist');
        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        $this->assertStringContainsString((string) $mine->title, $html);
        foreach (array_diff($victimTitles, [$mine->title]) as $title) {
            $this->assertStringNotContainsString(htmlspecialchars((string) $title), $html);
        }
    }

    public function testProfileNeverRevealsAnotherApplicantsSecret(): void
    {
        $this->seedWorld();
        $this->loginAsUser();

        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $this->assertStringNotContainsString('victim-secret-0123456789abcdef', (string) $this->client->getResponse()->getContent());
    }

    public function testAckSecretOnAnotherUsersApplicationIsForbidden(): void
    {
        $ids = $this->seedWorld();
        $intruder = $this->loginAsUser();
        // Give the intruder an approved app with a pending secret so a valid ack token is rendered.
        ApiApplicationFactory::new()->approved()->create([
            'applicant' => $intruder,
            'clientType' => ApiClientType::Confidential,
            'oauthClientId' => 'obc_intruder',
            'oauthPlainSecret' => 'intruder-secret',
        ]);
        $token = $this->client->request('GET', '/')
            ->filter('#profileModal form[action$="/ack-secret"] input[name="_token"]')->attr('value');
        $before = $this->dbSnapshot();

        $this->client->request('POST', '/profile/api/' . $ids['{app}'] . '/ack-secret', ['_token' => $token]);

        $this->assertResponseStatusCodeSame(403);
        $this->assertDbUnchanged($before, 'ack-secret on a foreign application');
        $this->assertSame(
            'victim-secret-0123456789abcdef',
            $this->em()->getRepository(ApiApplication::class)->find($ids['{app}'])->oauthPlainSecret,
        );
    }

    public function testVerificationLinkCannotBeRetargetedAtAnotherAccount(): void
    {
        $victim = UserFactory::new()->unverified()->create();
        $attacker = UserFactory::new()->unverified()->create();

        // A genuinely signed link for the attacker's own account, with the id swapped to the victim.
        $signed = static::getContainer()->get(VerifyEmailHelperInterface::class)->generateSignature(
            'app_verify_email',
            (string) $attacker->id,
            (string) $attacker->email,
            ['id' => (string) $attacker->id],
        )->getSignedUrl();
        $forged = str_replace('id=' . $attacker->id, 'id=' . $victim->id, $signed);
        $this->assertNotSame($signed, $forged);

        $this->client->request('GET', $forged);
        $this->client->request('GET', '/verify/email?id=' . $victim->id);

        $this->em()->clear();
        $this->assertFalse(
            $this->em()->getRepository(User::class)->find($victim->id)->isVerified,
            'a forged or unsigned verification link must not verify another account',
        );
    }
}

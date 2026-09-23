<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ApiApplication;
use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Enums\ApiApplicationStatus;
use App\Enums\MapSymbol;
use App\Enums\WishlistItemStatus;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\Api\OAuthApiTestCase;

/**
 * Mass assignment: fields a client must never set (roles, verification and
 * suspension flags, ids, provenance such as osmId/source/shortCode/legacyId,
 * ownership, workflow status) are ignored or rejected wherever they are
 * smuggled in — website forms, profile endpoints and /api/v1 JSON bodies.
 */
final class MassAssignmentTest extends OAuthApiTestCase
{
    use SecurityTestTrait;

    private const PROVENANCE = [
        'id' => '01HZZZZZZZZZZZZZZZZZZZZZZZ',
        'osmId' => 'n666',
        'source' => 'osm',
        'shortCode' => 'PWNED1',
        'legacyId' => '4242',
        'titleProvisional' => '1',
        'mapSymbol' => 'tardis',
        'createdAt' => '2000-01-01T00:00:00+00:00',
    ];

    // ── Website forms ──────────────────────────────────────────────────────

    public function testRegistrationIgnoresPrivilegeFields(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration_form[username]'] = 'massassign';
        $form['registration_form[email]'] = 'massassign@example.com';
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        $form['registration_form[agreeTerms]']->tick();

        $values = $form->getPhpValues();
        $values['registration_form'] += [
            'roles' => ['ROLE_ADMIN'],
            'isVerified' => '1',
            'isSuspended' => '0',
            'legacyUser' => '1',
            'legacyMigrated' => '1',
        ];
        $this->client->request('POST', $form->getUri(), $values);

        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => 'massassign']);
        if ($user !== null) {
            $this->assertNotContains('ROLE_ADMIN', $user->getRoles(), 'registration must not grant roles');
            $this->assertFalse($user->isVerified, 'registration must not self-verify');
            $this->assertFalse($user->legacyUser);
        } else {
            $this->addToAssertionCount(1); // rejected outright (extra fields) — also secure
        }
    }

    public function testQuickAddIgnoresProvenanceFields(): void
    {
        $this->loginAsUser();
        $form = $this->client->request('GET', '/api/bookcase/new?editable=1')->filter('#create-form')->form();
        $form['bookcase_create[title]'] = 'Mass Assigned';
        $form['bookcase_create[position][latitude]'] = '52.5';
        $form['bookcase_create[position][longitude]'] = '13.4';

        $values = $form->getPhpValues();
        $values['bookcase_create'] += self::PROVENANCE;
        $this->client->request('POST', $form->getUri(), $values);

        $this->assertNoProvenanceInjected();
    }

    public function testEditSaveIgnoresProvenanceFields(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::new()->osm('n1')->create(['shortCode' => 'ORIG01', 'title' => 'Original']);
        $id = (string) $bc->id;

        $form = $this->client->request('GET', '/api/bookcase/' . $id . '/edit')->filter('#edit-form')->form();
        $values = $form->getPhpValues();
        $values['bookcase'] += self::PROVENANCE;
        $values['bookcase']['caretakers'][99] = ['id' => 'foreign', 'name' => 'Injected'];
        $this->client->request('POST', $form->getUri(), $values);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertNotNull($reloaded, 'the id must not be rewritten');
        $this->assertSame('n1', $reloaded->osmId);
        $this->assertSame('osm', $reloaded->source);
        $this->assertSame('ORIG01', $reloaded->shortCode);
        $this->assertNull($reloaded->legacyId);
        $this->assertSame(MapSymbol::Standard, $reloaded->mapSymbol);
    }

    // ── Profile endpoints ──────────────────────────────────────────────────

    public function testProfileEndpointsNeverActOnAnotherUserById(): void
    {
        $victim = UserFactory::createOne(['email' => 'victim@example.com']);
        $me = $this->loginAsUser();
        $foreign = ['id' => (string) $victim->id, 'user' => (string) $victim->id, 'userId' => (string) $victim->id,
            'username' => $victim->username, 'roles' => ['ROLE_ADMIN']];

        $crawler = $this->client->request('GET', '/');
        $token = static fn (string $sel) => $crawler->filter($sel)->attr('value');
        $emailToken = $token('#profileModal form[data-action*="updateEmail"] input[name="_token"]');
        $homeToken = $token('#profileModal form[data-action*="updateHome"] input[name="_token"]');
        $deleteToken = $token('#profileModal input[data-profile-target="deleteToken"]');

        $this->client->request('POST', '/profile/email', ['_token' => $emailToken, 'email' => 'changed@example.com'] + $foreign);
        $this->client->request('POST', '/profile/home', ['_token' => $homeToken, 'latitude' => 10, 'longitude' => 20, 'enabled' => 1] + $foreign);

        $this->em()->clear();
        $users = $this->em()->getRepository(User::class);
        $reVictim = $users->find($victim->id);
        $this->assertSame('victim@example.com', $reVictim->email);
        $this->assertNull($reVictim->homeLatitude);
        $reMe = $users->find($me->id);
        $this->assertSame('changed@example.com', $reMe->email, 'the caller themselves is the one updated');
        $this->assertNotContains('ROLE_ADMIN', $reMe->getRoles());

        $this->client->request('POST', '/profile/delete', ['_token' => $deleteToken] + $foreign);
        $this->em()->clear();
        $this->assertNotNull($users->find($victim->id), 'deleting "my" account must never delete someone else');
    }

    public function testApiApplicationCannotBeSelfApproved(): void
    {
        UserFactory::new()->admin()->create();
        $victim = UserFactory::createOne();
        $me = $this->loginAsUser();
        $token = $this->client->request('GET', '/')
            ->filter('#profileModal form[data-action*="applyApi"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/profile/api/apply', [
            '_token' => $token,
            'appName' => 'Sneaky App',
            'useCase' => 'A perfectly reasonable use case description for the review.',
            'clientType' => 'confidential',
            'redirectUris' => 'https://example.com/cb',
            'scopes' => ['bookcases.write', 'admin', 'ROLE_ADMIN'],
            'status' => 'approved',
            'oauthClientId' => 'obc_self_minted',
            'applicant' => (string) $victim->id,
            'decidedBy' => (string) $me->id,
        ]);
        $this->assertResponseStatusCodeSame(201);

        $this->em()->clear();
        $app = $this->em()->getRepository(ApiApplication::class)->findOneBy(['appName' => 'Sneaky App']);
        $this->assertSame(ApiApplicationStatus::Pending, $app->status);
        $this->assertNull($app->oauthClientId);
        $this->assertNull($app->decidedBy);
        $this->assertSame((string) $me->id, (string) $app->applicant->id);
        $this->assertSame(['bookcases.write'], $app->requestedScopes, 'unknown scopes are dropped');
    }

    // ── /api/v1 JSON ───────────────────────────────────────────────────────

    public function testApiCreateIgnoresProvenanceFields(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('POST', '/api/v1/bookcases', $token, ['title' => 'Mass Assigned', 'latitude' => 1, 'longitude' => 2] + self::PROVENANCE);

        $this->assertSame(201, $this->statusCode());
        $this->assertNotSame(self::PROVENANCE['id'], $this->json()['id'] ?? null);
        $this->assertNoProvenanceInjected();
    }

    public function testApiPatchIgnoresProvenanceFields(): void
    {
        $bc = BookcaseFactory::new()->osm('n1')->create(['shortCode' => 'ORIG01']);
        $id = (string) $bc->id;
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);

        $this->api('PATCH', '/api/v1/bookcases/' . $id, $token, ['title' => 'Patched'] + self::PROVENANCE);
        $this->assertSame(200, $this->statusCode());

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(Bookcase::class)->find($id);
        $this->assertSame('Patched', $reloaded->title);
        $this->assertSame('n1', $reloaded->osmId);
        $this->assertSame('osm', $reloaded->source);
        $this->assertSame('ORIG01', $reloaded->shortCode);
        $this->assertNull($reloaded->legacyId);
        $this->assertSame(MapSymbol::Standard, $reloaded->mapSymbol);
    }

    public function testApiWishCannotBeCreatedForSomeoneElseOrInAnotherState(): void
    {
        $victim = UserFactory::createOne();
        $bc = BookcaseFactory::createOne();
        $caller = UserFactory::createOne();
        $token = $this->tokenFor($caller, ['wishlist.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bc->id . '/wishlist', $token, [
            'title' => 'Smuggled', 'status' => 'fulfilled',
            'user' => (string) $victim->id, 'droppedBy' => (string) $victim->id,
        ]);
        $this->assertSame(201, $this->statusCode());

        $this->em()->clear();
        $wish = $this->em()->getRepository(WishlistItem::class)->findOneBy(['title' => 'Smuggled']);
        $this->assertSame(WishlistItemStatus::Open, $wish->status);
        $this->assertSame((string) $caller->id, (string) $wish->user->id);
        $this->assertNull($wish->droppedBy);
    }

    private function assertNoProvenanceInjected(): void
    {
        $this->em()->clear();
        $repo = $this->em()->getRepository(Bookcase::class);
        $this->assertSame(0, $repo->count(['osmId' => 'n666']), 'osmId must not be client-settable');
        $this->assertSame(0, $repo->count(['shortCode' => 'PWNED1']), 'shortCode must not be client-settable');
        $this->assertSame(0, $repo->count(['source' => 'osm']), 'source must not be client-settable');
        $this->assertSame(0, $repo->count(['legacyId' => 4242]), 'legacyId must not be client-settable');
        $this->assertSame(0, $repo->count(['mapSymbol' => MapSymbol::Tardis]), 'mapSymbol is not part of quick-add/create');
        $this->assertSame(0, $repo->count(['titleProvisional' => true]));
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\Api\OAuthApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Cross-resource / cross-user access on the public /api/v1: a caretaker or
 * opening time of one bookcase must not be editable through another bookcase's
 * URL, and the per-user resources (rating, watch, home) only ever touch the
 * token owner's own data, whatever ids the request body smuggles in.
 */
final class ApiIdorTest extends OAuthApiTestCase
{
    use SecurityTestTrait;

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function childUnderWrongParent(): iterable
    {
        yield 'caretaker update' => ['PATCH', '/api/v1/bookcases/{other}/caretakers/{caretaker}', ['name' => 'Hijacked']];
        yield 'caretaker delete' => ['DELETE', '/api/v1/bookcases/{other}/caretakers/{caretaker}', []];
        yield 'opening time update' => ['PATCH', '/api/v1/bookcases/{other}/opening-times/{ot}', ['openTime' => 'never']];
        yield 'opening time delete' => ['DELETE', '/api/v1/bookcases/{other}/opening-times/{ot}', []];
    }

    #[DataProvider('childUnderWrongParent')]
    public function testChildResourceIsNotReachableUnderAnotherBookcase(string $method, string $path, array $body): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        $before = $this->dbSnapshot();

        $this->api($method, $url, $token, $body);

        $this->assertSame(404, $this->statusCode(), "$method $path");
        $this->assertDbUnchanged($before, "$method $path");
    }

    public function testRatingDeleteOnlyRemovesTheCallersOwnRating(): void
    {
        $ids = $this->seedWorld();
        $token = $this->tokenFor(UserFactory::createOne(), ['ratings.write']);

        $this->api('DELETE', '/api/v1/bookcases/' . $ids['{bc}'] . '/rating', $token, ['user' => $ids['{user}']]);
        $this->assertSame(200, $this->statusCode());

        $this->em()->clear();
        $this->assertNotNull(
            $this->em()->getRepository(Rating::class)->findOneBy(['user' => $ids['{user}']]),
            "another user's rating must survive",
        );
    }

    public function testRatingUpsertIsAttributedToTheTokenUser(): void
    {
        $ids = $this->seedWorld();
        $caller = UserFactory::createOne();
        $token = $this->tokenFor($caller, ['ratings.write']);

        $this->api('PUT', '/api/v1/bookcases/' . $ids['{bc}'] . '/rating', $token, ['value' => 1, 'user' => $ids['{user}'], 'userId' => $ids['{user}']]);
        $this->assertSame(200, $this->statusCode());

        $this->em()->clear();
        $ratings = $this->em()->getRepository(Rating::class);
        $this->assertSame(5, $ratings->findOneBy(['user' => $ids['{user}']])->value, "victim's rating untouched");
        $this->assertSame(1, $ratings->findOneBy(['user' => (string) $caller->id])?->value);
    }

    public function testUnwatchOnlyRemovesTheCallersOwnWatch(): void
    {
        $ids = $this->seedWorld();
        $token = $this->tokenFor(UserFactory::createOne(), ['watchlist.write']);

        $this->api('DELETE', '/api/v1/bookcases/' . $ids['{bc}'] . '/watch', $token, ['user' => $ids['{user}']]);
        $this->assertSame(200, $this->statusCode());

        $this->em()->clear();
        $this->assertSame(1, $this->em()->getRepository(WatchlistItem::class)->count(['user' => $ids['{user}']]));
    }

    public function testHomeWriteOnlyTouchesTheTokenUser(): void
    {
        $ids = $this->seedWorld();
        $caller = UserFactory::createOne();
        $token = $this->tokenFor($caller, ['home.write']);

        $this->api('POST', '/api/v1/profile/home', $token, [
            'latitude' => 10, 'longitude' => 20,
            'id' => $ids['{user}'], 'user' => $ids['{user}'], 'username' => 'victim',
        ]);
        $this->assertSame(200, $this->statusCode());

        $this->em()->clear();
        $users = $this->em()->getRepository(User::class);
        $this->assertNull($users->find($ids['{user}'])->homeLatitude, "victim's home untouched");
        $this->assertSame(10.0, $users->find($caller->id)->homeLatitude);
    }
}

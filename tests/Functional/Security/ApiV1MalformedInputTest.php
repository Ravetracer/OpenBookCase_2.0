<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\DeletedBookcase;
use App\Entity\OpeningTime;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WishlistItem;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\Api\OAuthApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Hostile / malformed input against the public OAuth API (/api/v1). Requests
 * carry a real bearer token so they reach the controllers. Invariant: invalid
 * input → clean 4xx, never a 500, and never a persisted value a well-formed
 * request could not have produced (no "Array" strings, no coordinates coerced
 * from garbage, no out-of-range values).
 */
final class ApiV1MalformedInputTest extends OAuthApiTestCase
{
    private const ALL_SCOPES = ['bookcases.write', 'bookcases.delete', 'ratings.write', 'wishlist.write', 'home.write', 'images.write', 'watchlist.write'];

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function token(): string
    {
        $token = $this->tokenFor(UserFactory::createOne(), self::ALL_SCOPES);
        $this->assertNotSame('', $token, 'could not mint an access token');

        return $token;
    }

    /** Send a raw (possibly invalid) body with a bearer token. */
    private function raw(string $method, string $url, string $token, string $body): void
    {
        $this->client->request($method, $url, [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], $body);
    }

    private function assertClientError(string $context): void
    {
        $status = $this->statusCode();
        $this->assertGreaterThanOrEqual(400, $status, "$context: expected a 4xx, got $status");
        $this->assertLessThan(500, $status, "$context: expected a 4xx, got $status (server error)");
    }

    private function reload(Bookcase $bc): ?Bookcase
    {
        $this->em()->clear();

        return $this->em()->getRepository(Bookcase::class)->find($bc->id);
    }

    // ── Invalid ids ───────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'garbage' => ['not-a-ulid'];
        yield 'sql' => ["1' OR '1'='1"];
        yield 'numeric' => ['12345'];
        yield 'nonexistent ulid' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'];
    }

    #[DataProvider('invalidIds')]
    public function testInvalidBookcaseIdsReturn404(string $id): void
    {
        $seg = rawurlencode($id);

        foreach (['', '/caretakers', '/opening-times', '/rating', '/wishlist', '/images'] as $suffix) {
            $this->api('GET', "/api/v1/bookcases/$seg$suffix");
            $this->assertSame(404, $this->statusCode(), "GET /api/v1/bookcases/{id}$suffix with '$id'");
        }

        $token = $this->token();
        $writes = [
            ['PATCH', '', ['title' => 'x']],
            ['DELETE', '', ['reason' => 'x']],
            ['POST', '/position', ['latitude' => 1, 'longitude' => 1]],
            ['POST', '/caretakers', ['name' => 'x']],
            ['POST', '/opening-times', ['openTime' => 'x']],
            ['PUT', '/rating', ['value' => 3]],
            ['POST', '/wishlist', ['title' => 'x']],
            ['POST', '/watch', []],
        ];
        foreach ($writes as [$method, $suffix, $body]) {
            $this->api($method, "/api/v1/bookcases/$seg$suffix", $token, $body);
            $this->assertSame(404, $this->statusCode(), "$method /api/v1/bookcases/{id}$suffix with '$id'");
        }
    }

    #[DataProvider('invalidIds')]
    public function testInvalidSubResourceIdsReturn404(string $id): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->token();
        $seg = rawurlencode($id);

        foreach (['caretakers', 'opening-times'] as $kind) {
            $this->api('PATCH', "/api/v1/bookcases/{$bc->id}/$kind/$seg", $token, ['name' => 'x', 'openTime' => 'x']);
            $this->assertSame(404, $this->statusCode(), "PATCH $kind/{id} with '$id'");
            $this->api('DELETE', "/api/v1/bookcases/{$bc->id}/$kind/$seg", $token);
            $this->assertSame(404, $this->statusCode(), "DELETE $kind/{id} with '$id'");
        }
    }

    // ── Create: malformed bodies ──────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function badCreateBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['title=x&latitude=1'];
        yield 'json null' => ['null'];
        yield 'json scalar' => ['"hello"'];
        yield 'truncated' => ['{"title":"x"'];
        yield 'deeply nested' => [str_repeat('{"a":', 5000) . '1' . str_repeat('}', 5000)];
        yield 'title array' => ['{"title":["x"],"latitude":50,"longitude":10}'];
        yield 'title object' => ['{"title":{"a":1},"latitude":50,"longitude":10}'];
        yield 'title 100k' => ['{"title":"' . str_repeat('A', 100_000) . '","latitude":50,"longitude":10}'];
        yield 'title invalid utf8' => ["{\"title\":\"\xff\xfe\",\"latitude\":50,\"longitude\":10}"];
        yield 'lat text' => ['{"title":"Ok","latitude":"abc","longitude":"abc"}'];
        yield 'lat array' => ['{"title":"Ok","latitude":[50],"longitude":[10]}'];
        yield 'lat out of range' => ['{"title":"Ok","latitude":91,"longitude":10}'];
        yield 'lon out of range' => ['{"title":"Ok","latitude":50,"longitude":-181}'];
        yield 'lat overflow' => ['{"title":"Ok","latitude":1e309,"longitude":10}'];
        yield 'entryType array' => ['{"title":"Ok","latitude":50,"longitude":10,"entryType":["bookcase"]}'];
        yield 'entryType junk' => ['{"title":"Ok","latitude":50,"longitude":10,"entryType":"bookcase\' OR 1=1"}'];
        yield 'installationType object' => ['{"title":"Ok","latitude":50,"longitude":10,"installationType":{"a":1}}'];
    }

    #[DataProvider('badCreateBodies')]
    public function testCreateRejectsMalformedBodies(string $body): void
    {
        $token = $this->token();

        $this->raw('POST', '/api/v1/bookcases', $token, $body);
        $this->assertClientError('create ' . substr($body, 0, 40));
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]), 'nothing may be persisted');
    }

    public function testCreateBaselineWorks(): void
    {
        $token = $this->token();
        $this->raw('POST', '/api/v1/bookcases', $token, '{"title":"Ok","latitude":50,"longitude":10}');
        $this->assertSame(201, $this->statusCode());
    }

    // ── Update: type confusion must not corrupt the entry ─────────────────

    /** @return iterable<string, array{string}> */
    public static function badPatchBodies(): iterable
    {
        yield 'title array' => ['{"title":["x"]}'];
        yield 'title object' => ['{"title":{"a":1}}'];
        yield 'title 100k' => ['{"title":"' . str_repeat('A', 100_000) . '"}'];
        yield 'lat text' => ['{"latitude":"abc"}'];
        yield 'lon text' => ['{"longitude":"abc"}'];
        yield 'lat array' => ['{"latitude":[1]}'];
        yield 'lat overflow' => ['{"latitude":1e309}'];
        yield 'lat out of range' => ['{"latitude":-91}'];
        yield 'webpage object' => ['{"webpage":{"a":1}}'];
        yield 'comment array' => ['{"comment":["x"]}'];
        yield 'activeStatus array' => ['{"activeStatus":["active"]}'];
        yield 'entryType object' => ['{"entryType":{"a":1}}'];
        yield 'accessibilityLevel text' => ['{"accessibilityLevel":"abc"}'];
        yield 'accessibilityLevel out of range' => ['{"accessibilityLevel":99}'];
        yield 'address street array' => ['{"address":{"street":["x"]}}'];
    }

    #[DataProvider('badPatchBodies')]
    public function testUpdateRejectsMalformedBodies(string $body): void
    {
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Original Title', 'comment' => null, 'webpage' => null]);
        $token = $this->token();

        $this->raw('PATCH', "/api/v1/bookcases/{$bc->id}", $token, $body);
        $this->assertClientError('patch ' . substr($body, 0, 40));

        $fresh = $this->reload($bc);
        $this->assertSame('Original Title', $fresh->title);
        $this->assertSame(50.0, $fresh->position->latitude);
        $this->assertSame(10.0, $fresh->position->longitude);
        $this->assertNull($fresh->webpage);
        $this->assertNull($fresh->comment);
        $this->assertNotSame('Array', $fresh->address?->street);
    }

    /**
     * A PATCH rejected by validation (here: the anti-spam "no URL in title" rule)
     * must leave the stored entry untouched — including after any later flush in
     * the same request lifecycle (e.g. telemetry on kernel.terminate).
     */
    #[DataProvider('rejectedPatchBodies')]
    public function testRejectedUpdateIsNotPersisted(string $body): void
    {
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Original Title']);
        $token = $this->token();

        $this->raw('PATCH', "/api/v1/bookcases/{$bc->id}", $token, $body);
        $this->assertClientError('patch ' . $body);

        $fresh = $this->reload($bc);
        $this->assertSame('Original Title', $fresh->title, 'rejected title must not be persisted');
        $this->assertSame(50.0, $fresh->position->latitude, 'rejected position must not be persisted');
    }

    /** @return iterable<string, array{string}> */
    public static function rejectedPatchBodies(): iterable
    {
        yield 'url in title' => ['{"title":"Cheap pills https://spam.example"}'];
        yield 'blank title' => ['{"title":"   "}'];
        yield 'valid title + invalid entryType' => ['{"title":"Sneaky","entryType":"junk"}'];
        yield 'valid title + invalid status' => ['{"title":"Sneaky","activeStatus":"junk"}'];
        yield 'valid lat + invalid accessibility' => ['{"latitude":-12.5,"accessibilityLevel":99}'];
        yield 'out of range lon' => ['{"title":"Sneaky","longitude":999}'];
    }

    public function testUpdateWithNonJsonBodyChangesNothing(): void
    {
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create(['title' => 'Original Title']);
        $token = $this->token();

        $this->raw('PATCH', "/api/v1/bookcases/{$bc->id}", $token, 'title=Hacked');
        $this->assertLessThan(500, $this->statusCode());
        $this->assertSame('Original Title', $this->reload($bc)->title);
    }

    // ── Position ──────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function badPositionBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'text' => ['{"latitude":"abc","longitude":"def"}'];
        yield 'NaN string' => ['{"latitude":"NaN","longitude":"NaN"}'];
        yield 'INF string' => ['{"latitude":"INF","longitude":"-INF"}'];
        yield 'overflow literal' => ['{"latitude":1e309,"longitude":0}'];
        yield 'overflow string' => ['{"latitude":"1e309","longitude":"0"}'];
        yield 'lat 91' => ['{"latitude":91,"longitude":0}'];
        yield 'lon -181' => ['{"latitude":0,"longitude":-181}'];
        yield 'arrays' => ['{"latitude":[1],"longitude":[1]}'];
        yield 'objects' => ['{"latitude":{"a":1},"longitude":{"a":1}}'];
        yield 'null' => ['{"latitude":null,"longitude":null}'];
        yield 'bool' => ['{"latitude":true,"longitude":true}'];
    }

    #[DataProvider('badPositionBodies')]
    public function testPositionRejectsBadCoordinates(string $body): void
    {
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create();
        $token = $this->token();

        $this->raw('POST', "/api/v1/bookcases/{$bc->id}/position", $token, $body);
        $this->assertSame(400, $this->statusCode(), 'position ' . $body);

        $fresh = $this->reload($bc);
        $this->assertSame(50.0, $fresh->position->latitude);
        $this->assertSame(10.0, $fresh->position->longitude);
    }

    #[DataProvider('badPositionBodies')]
    public function testProfileHomeRejectsBadCoordinates(string $body): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['home.write']);

        $this->raw('POST', '/api/v1/profile/home', $token, $body);
        $this->assertSame(400, $this->statusCode(), 'home ' . $body);

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(User::class)->find($user->id)->homeLatitude);
    }

    public function testProfileHomeClampsZoomAndLabel(): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['home.write']);

        $this->raw('POST', '/api/v1/profile/home', $token, '{"latitude":1,"longitude":1,"zoom":99999999999999999999,"label":"' . str_repeat('L', 10_000) . '"}');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThanOrEqual(1, $this->json()['zoom']);
        $this->assertLessThanOrEqual(19, $this->json()['zoom']);
        $this->assertLessThanOrEqual(50, mb_strlen((string) $this->json()['label']));
    }

    public function testProfileHomeRejectsArrayLabel(): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['home.write']);

        $this->raw('POST', '/api/v1/profile/home', $token, '{"latitude":1,"longitude":1,"label":["Home"]}');
        $this->assertLessThan(500, $this->statusCode());

        $this->em()->clear();
        $this->assertNotSame('Array', $this->em()->getRepository(User::class)->find($user->id)->homeLabel, 'array label must not be stored as "Array"');
    }

    // ── Soft delete reason ────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function badDeleteBodies(): iterable
    {
        yield 'empty' => [''];
        yield 'not json' => ['reason=spam'];
        yield 'reason missing' => ['{}'];
        yield 'reason blank' => ['{"reason":"  "}'];
        yield 'reason object' => ['{"reason":{"a":1}}'];
        yield 'reason list' => ['{"reason":["spam"]}'];
        yield 'reason false' => ['{"reason":false}'];
    }

    #[DataProvider('badDeleteBodies')]
    public function testDeleteRequiresARealReason(string $body): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->token();

        $this->raw('DELETE', "/api/v1/bookcases/{$bc->id}", $token, $body);
        $this->assertClientError('delete ' . $body);

        $this->assertNotNull($this->reload($bc), 'bookcase must survive');
        $this->assertSame(0, $this->em()->getRepository(DeletedBookcase::class)->count([]));
    }

    // ── Rating ────────────────────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function badRatingBodies(): iterable
    {
        yield 'zero' => ['{"value":0}'];
        yield 'six' => ['{"value":6}'];
        yield 'negative' => ['{"value":-1}'];
        yield 'text' => ['{"value":"abc"}'];
        yield 'null' => ['{"value":null}'];
        yield 'missing' => ['{}'];
        yield 'huge' => ['{"value":99999999999999999999}'];
        yield 'overflow' => ['{"value":1e309}'];
        yield 'not json' => ['value=5'];
    }

    #[DataProvider('badRatingBodies')]
    public function testRatingOutOfRangeIsRejected(string $body): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->token();

        $this->raw('PUT', "/api/v1/bookcases/{$bc->id}/rating", $token, $body);
        $this->assertClientError('rating ' . $body);
        $this->assertSame(0, $this->em()->getRepository(Rating::class)->count([]));
    }

    /** @return iterable<string, array{string}> */
    public static function coercedRatingBodies(): iterable
    {
        yield 'fraction' => ['{"value":3.5}'];
        yield 'numeric string' => ['{"value":"4"}'];
        yield 'numeric prefix sql' => ['{"value":"5 OR 1=1"}'];
        yield 'array' => ['{"value":[5]}'];
        yield 'bool' => ['{"value":true}'];
    }

    /** Loosely-typed values are coerced; whatever is stored must be an int in 1..5. */
    #[DataProvider('coercedRatingBodies')]
    public function testCoercedRatingStaysInRange(string $body): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->token();

        $this->raw('PUT', "/api/v1/bookcases/{$bc->id}/rating", $token, $body);
        $this->assertLessThan(500, $this->statusCode());
        foreach ($this->em()->getRepository(Rating::class)->findAll() as $rating) {
            $this->assertIsInt($rating->value);
            $this->assertGreaterThanOrEqual(1, $rating->value);
            $this->assertLessThanOrEqual(5, $rating->value);
        }
    }

    // ── Sub-resources: arrays for strings ─────────────────────────────────

    /** @return iterable<string, array{string, string, class-string, string}> */
    public static function arrayStringFields(): iterable
    {
        yield 'wishlist title' => ['/wishlist', '{"title":["x"]}', WishlistItem::class, 'title'];
        yield 'wishlist author' => ['/wishlist', '{"title":"Book","author":{"a":1}}', WishlistItem::class, 'author'];
        yield 'caretaker name' => ['/caretakers', '{"name":["x"]}', Caretaker::class, 'name'];
        yield 'caretaker contact' => ['/caretakers', '{"name":"Jane","contact":{"a":1}}', Caretaker::class, 'contact'];
        yield 'opening time' => ['/opening-times', '{"openTime":["Mo-Fr"]}', OpeningTime::class, 'open_time'];
    }

    /** @param class-string $entity */
    #[DataProvider('arrayStringFields')]
    public function testSubResourceArrayForStringIsRejected(string $suffix, string $body, string $entity, string $field): void
    {
        $bc = BookcaseFactory::createOne();
        $token = $this->token();

        $this->raw('POST', "/api/v1/bookcases/{$bc->id}$suffix", $token, $body);
        $this->assertClientError("$suffix $field");

        $this->em()->clear();
        foreach ($this->em()->getRepository($entity)->findAll() as $row) {
            $this->assertNotSame('Array', $row->{$field}, "$entity::$field stored as \"Array\"");
        }
    }

    // ── Method confusion ──────────────────────────────────────────────────

    public function testWriteRoutesDoNotAcceptGet(): void
    {
        $bc = BookcaseFactory::new()->at(50.0, 10.0)->create();
        $token = $this->token();

        $this->api('GET', "/api/v1/bookcases/{$bc->id}/position?latitude=1&longitude=1", $token);
        $this->assertSame(405, $this->statusCode());
        $this->api('GET', "/api/v1/bookcases/{$bc->id}/watch", $token);
        $this->assertSame(405, $this->statusCode());
        $this->api('GET', '/api/v1/profile/home?latitude=1&longitude=1', $token);
        $this->assertSame(405, $this->statusCode());

        $this->assertSame(50.0, $this->reload($bc)->position->latitude);
    }
}

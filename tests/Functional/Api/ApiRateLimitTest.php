<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\UserFactory;

/**
 * The write rate limiter (60 / 10 min, keyed by token) must reject once exceeded.
 * The in-memory limiter cache pool (when@test) is fresh per kernel, so the count
 * starts at zero for this test.
 */
final class ApiRateLimitTest extends OAuthApiTestCase
{
    public function testWritesAreRateLimitedAfterTheLimit(): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['bookcases.write']);

        $statuses = [];
        // 60 are allowed; the 61st must be 429. Bodies are intentionally empty
        // (so allowed requests 422), proving the limiter runs before the controller.
        for ($i = 0; $i < 61; ++$i) {
            $this->api('POST', '/api/v1/bookcases', $token, []);
            $statuses[] = $this->statusCode();
        }

        self::assertNotContains(429, array_slice($statuses, 0, 60), 'First 60 writes must not be rate-limited.');
        self::assertSame(429, $statuses[60], 'The 61st write must be rejected with 429.');
        self::assertSame(
            '60',
            $this->client->getResponse()->headers->get('X-RateLimit-Limit'),
            'A 429 must advertise the limit.',
        );
        self::assertNotNull(
            $this->client->getResponse()->headers->get('Retry-After'),
            'A 429 must carry a Retry-After header.',
        );
    }

    public function testReadsAreNotBlockedAtLowVolume(): void
    {
        // A handful of open reads stays well under the 600/min read budget.
        for ($i = 0; $i < 5; ++$i) {
            $this->api('GET', '/api/v1/bookcases?latMin=0&latMax=1&lonMin=0&lonMax=1');
            self::assertNotSame(429, $this->statusCode());
        }
    }
}

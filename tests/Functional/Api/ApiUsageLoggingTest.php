<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Repository\ApiUsageLogRepository;
use App\Tests\Factory\UserFactory;

/**
 * The kernel.terminate telemetry subscriber must record one ApiUsageLog row per
 * authenticated /api/v1 request — and must NOT record anonymous public-data reads.
 */
final class ApiUsageLoggingTest extends OAuthApiTestCase
{
    private function logs(): ApiUsageLogRepository
    {
        return static::getContainer()->get(ApiUsageLogRepository::class);
    }

    public function testAuthenticatedWriteIsLogged(): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['bookcases.write']);

        $this->api('POST', '/api/v1/bookcases', $token, [
            'title' => 'Logged Shelf',
            'entryType' => 'bookcase',
            'latitude' => 52.52,
            'longitude' => 13.4,
        ]);
        self::assertSame(201, $this->statusCode());

        $all = $this->logs()->findAll();
        self::assertCount(1, $all);

        $log = $all[0];
        self::assertSame('POST', $log->method);
        self::assertSame('api_v1_bookcases_create', $log->routeName);
        self::assertSame('/api/v1/bookcases', $log->path);
        self::assertSame(201, $log->statusCode);
        self::assertNotNull($log->oauthClientId);
        self::assertNotNull($log->apiApplication);
        self::assertNotNull($log->actingUser);
        self::assertSame($user->id->toRfc4122(), $log->actingUser->id->toRfc4122());
        self::assertSame('Logged Shelf', $log->requestPayload['title'] ?? null);
    }

    public function testFailedWriteIsLoggedWithItsStatus(): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['bookcases.write']);

        // Empty body → validation failure (422), but the attempt is still audited.
        $this->api('POST', '/api/v1/bookcases', $token, []);
        self::assertSame(422, $this->statusCode());

        $all = $this->logs()->findAll();
        self::assertCount(1, $all);
        self::assertSame(422, $all[0]->statusCode);
    }

    public function testAnonymousReadIsNotLogged(): void
    {
        $this->api('GET', '/api/v1/bookcases?latMin=0&latMax=1&lonMin=0&lonMax=1');
        self::assertSame(200, $this->statusCode());

        self::assertCount(0, $this->logs()->findAll());
    }
}

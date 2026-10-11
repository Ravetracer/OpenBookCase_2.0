<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Bookcase;
use App\Tests\Factory\BookcaseFactory;

/**
 * BookcaseViewRateLimitSubscriber: single-entry views (deep link, short link,
 * detail fragment, detail JSON) share one per-user / per-IP budget. The burst
 * window allows 40 views per minute (config/packages/rate_limiter.yaml).
 */
final class BookcaseViewRateLimitTest extends FunctionalTestCase
{
    private const BURST = 40;

    public function testDeepLinkPageIsLimitedWithTranslatedPage(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.1', $bookcase);

        $this->client->request('GET', '/bookcase/' . $bookcase->id, server: ['REMOTE_ADDR' => '10.0.0.1']);

        $this->assertResponseStatusCodeSame(429);
        $this->assertResponseHasHeader('Retry-After');
        $this->assertGreaterThanOrEqual(1, (int) $this->client->getResponse()->headers->get('Retry-After'));
        $this->assertSelectorTextContains('h1', 'Slow down a little');
    }

    public function testShortLinkIsLimited(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.2', $bookcase);

        $this->client->request('GET', '/s/' . $bookcase->shortCode, server: ['REMOTE_ADDR' => '10.0.0.2']);

        $this->assertResponseStatusCodeSame(429);
    }

    public function testDetailFragmentIsLimitedWithEmptyBody(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.3', $bookcase);

        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '10.0.0.3']);

        $this->assertResponseStatusCodeSame(429);
        $this->assertSame('', $this->client->getResponse()->getContent());
    }

    public function testDetailJsonIsLimitedWithJsonError(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.4', $bookcase);

        $this->client->request('GET', '/api/bookcase/' . $bookcase->id, server: ['REMOTE_ADDR' => '10.0.0.4']);

        $this->assertResponseStatusCodeSame(429);
        $this->assertArrayHasKey('error', json_decode((string) $this->client->getResponse()->getContent(), true));
    }

    public function testUnknownIdsCountTowardsTheBudget(): void
    {
        // Scrapers guessing ids must not get a free pass via 404s.
        for ($i = 0; $i < self::BURST; ++$i) {
            $this->client->request('GET', '/api/bookcase/01AAAAAAAAAAAAAAAAAAAAAAAA/html', server: ['REMOTE_ADDR' => '10.0.0.5']);
        }

        $this->client->request('GET', '/api/bookcase/01AAAAAAAAAAAAAAAAAAAAAAAA/html', server: ['REMOTE_ADDR' => '10.0.0.5']);

        $this->assertResponseStatusCodeSame(429);
    }

    public function testNormalBrowsingStaysUnderTheLimit(): void
    {
        $bookcase = BookcaseFactory::createOne();

        for ($i = 0; $i < self::BURST; ++$i) {
            $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '10.0.0.6']);
            $this->assertResponseIsSuccessful();
        }
    }

    public function testOtherIpHasItsOwnBudget(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.7', $bookcase);

        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '10.0.0.8']);

        $this->assertResponseIsSuccessful();
    }

    public function testIpv6ClientsAreKeyedByTheirSlash64(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('2001:db8:1:2::1', $bookcase);

        // Rotating addresses inside the same /64 does not reset the budget …
        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '2001:db8:1:2:ffff::42']);
        $this->assertResponseStatusCodeSame(429);

        // … while a different /64 is a different client.
        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '2001:db8:1:3::1']);
        $this->assertResponseIsSuccessful();
    }

    public function testSpoofedForwardedForDoesNotBypassTheLimit(): void
    {
        // No trusted proxies are configured, so X-Forwarded-For must be ignored.
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.9', $bookcase);

        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: [
            'REMOTE_ADDR' => '10.0.0.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.7',
        ]);

        $this->assertResponseStatusCodeSame(429);
    }

    public function testLoggedInUserIsKeyedByAccountNotIp(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.10', $bookcase);

        $this->loginAsUser();
        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '10.0.0.10']);

        $this->assertResponseIsSuccessful();
    }

    public function testLoggedInUserIsLimitedToo(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->loginAsUser();

        // Spread over several IPs: the account budget still runs out.
        for ($i = 0; $i < self::BURST; ++$i) {
            $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '10.1.0.' . $i]);
        }
        $this->client->request('GET', '/api/bookcase/' . $bookcase->id . '/html', server: ['REMOTE_ADDR' => '10.1.1.1']);

        $this->assertResponseStatusCodeSame(429);
    }

    public function testMapAndListAreNotLimited(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->exhaust('10.0.0.11', $bookcase);

        $this->client->request('GET', '/', server: ['REMOTE_ADDR' => '10.0.0.11']);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/api/bookcase/?latMin=-90&latMax=90&lonMin=-180&lonMax=180', server: ['REMOTE_ADDR' => '10.0.0.11']);
        $this->assertResponseIsSuccessful();

        $this->client->request('GET', '/list', server: ['REMOTE_ADDR' => '10.0.0.11']);
        $this->assertResponseIsSuccessful();
    }

    /** Use up the burst budget for one client, mixing all limited routes. */
    private function exhaust(string $ip, Bookcase $bookcase): void
    {
        $urls = [
            '/bookcase/' . $bookcase->id,
            '/s/' . $bookcase->shortCode,
            '/api/bookcase/' . $bookcase->id . '/html',
            '/api/bookcase/' . $bookcase->id,
        ];

        for ($i = 0; $i < self::BURST; ++$i) {
            $this->client->request('GET', $urls[$i % \count($urls)], server: ['REMOTE_ADDR' => $ip]);
            $this->assertResponseIsSuccessful();
        }
    }
}

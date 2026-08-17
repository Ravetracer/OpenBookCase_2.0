<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Bookcase;
use App\Entity\DeletedBookcase;
use App\Tests\Factory\BookcaseFactory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The internal, cookie-authenticated JSON API under /api/bookcase must reject
 * cross-origin state-changing requests (CSRF), while same-origin / non-browser
 * requests keep working. See App\EventSubscriber\ApiCsrfSubscriber.
 */
final class ApiCsrfTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testCrossSiteFetchWriteIsBlocked(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        // A browser attaches Sec-Fetch-Site: cross-site to a scripted cross-origin
        // write; the attacker page cannot suppress it.
        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/rating',
            ['value' => 4],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCrossOriginHeaderWriteIsBlocked(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/rating',
            ['value' => 4],
            [],
            ['HTTP_ORIGIN' => 'https://evil.example'],
        );

        $this->assertResponseStatusCodeSame(403);
    }

    public function testCrossOriginDeleteIsBlocked(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;

        $this->client->request(
            'DELETE',
            '/api/bookcase/' . $id,
            [],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['reason' => 'csrf attempt']),
        );

        $this->assertResponseStatusCodeSame(403);

        // The entry must still exist (nothing archived).
        $this->em()->clear();
        $this->assertNotNull($this->em()->getRepository(Bookcase::class)->find($id));
        $this->assertSame(0, $this->em()->getRepository(DeletedBookcase::class)->count([]));
    }

    public function testSameOriginFetchWriteIsAllowed(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/rating',
            ['value' => 4],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );

        $this->assertResponseIsSuccessful();
        $this->assertSame('success', $this->json()['status']);
    }

    public function testSameHostRefererWriteIsAllowed(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        // No Sec-Fetch-Site (older browser) but a same-host Referer → allowed.
        $this->client->request(
            'POST',
            '/api/bookcase/' . $bc->id . '/rating',
            ['value' => 3],
            [],
            ['HTTP_REFERER' => 'http://localhost/'],
        );

        $this->assertResponseIsSuccessful();
    }

    public function testGetReadIsNeverBlocked(): void
    {
        $bc = BookcaseFactory::createOne();

        // Safe methods are exempt even from a cross-site origin.
        $this->client->request(
            'GET',
            '/api/bookcase/' . $bc->id,
            [],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'cross-site'],
        );

        $this->assertResponseIsSuccessful();
    }
}

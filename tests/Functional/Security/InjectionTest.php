<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ApiUsageLog;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SQL/DQL injection attempts against every query-driving parameter. The
 * invariant: a hostile value never yields a 500 / leaked SQL error, never
 * widens the result set (a payload that is not a real title matches nothing),
 * and unknown sort keys / directions fall back to the whitelist.
 */
final class InjectionTest extends FunctionalTestCase
{
    /** @return iterable<string, array{string}> */
    public static function sqlPayloads(): iterable
    {
        yield 'tautology' => ["' OR 1=1 --"];
        yield 'tautology double quote' => ['" OR "1"="1'];
        yield 'stacked drop' => ['1; DROP TABLE bookcase'];
        yield 'paren close' => ['bc.id) --'];
        yield 'order by subquery' => ['title DESC, (SELECT 1)'];
        yield 'union' => ["x' UNION SELECT id, title FROM bookcase --"];
        yield 'dql function' => ['LOWER(bc.title)'];
        yield 'comment' => ['/* */'];
        yield 'backslash' => ['Alpha\\'];
        yield 'backslash quote' => ["\\' OR 1=1 --"];
    }

    /** @return iterable<string, array{string}> */
    public static function sqlAndWildcardPayloads(): iterable
    {
        yield from self::sqlPayloads();
        yield 'percent' => ['%'];
        yield 'underscore' => ['_'];
    }

    private function seedTwo(): void
    {
        BookcaseFactory::new()->at(52.5, 13.4)->create(['title' => 'Alpha Reading Spot']);
        BookcaseFactory::new()->at(52.6, 13.5)->create(['title' => 'Beta Reading Spot']);
    }

    private function listRowCount(): int
    {
        return $this->client->getCrawler()->filter('[data-bc-open]')->count();
    }

    private function assertNoServerError(): void
    {
        $status = $this->client->getResponse()->getStatusCode();
        $this->assertLessThan(500, $status, 'hostile input must not cause a server error');
        $body = (string) $this->client->getResponse()->getContent();
        foreach (['SQLSTATE', 'Doctrine\\DBAL', 'syntax error', 'QueryException'] as $leak) {
            $this->assertStringNotContainsString($leak, $body, "response leaks '$leak'");
        }
    }

    // ── /list, /list/fragment: free-text q ────────────────────────────────

    #[DataProvider('sqlPayloads')]
    public function testListQueryPayloadDoesNotWidenResults(string $payload): void
    {
        $this->seedTwo();

        foreach (['/list', '/list/fragment'] as $url) {
            $this->client->request('GET', $url, ['q' => $payload]);
            $this->assertResponseIsSuccessful();
            $this->assertNoServerError();
            $this->assertSame(0, $this->listRowCount(), "$url?q=$payload must not match any row");
        }
    }

    public function testListQueryStillMatchesLegitimately(): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/list/fragment', ['q' => 'Alpha']);
        $this->assertSame(1, $this->listRowCount());
    }

    #[DataProvider('sqlAndWildcardPayloads')]
    public function testListSortAndDirPayloadsFallBackToWhitelist(string $payload): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/list/fragment', ['sort' => $payload, 'dir' => $payload]);
        $this->assertResponseIsSuccessful();
        $this->assertNoServerError();
        $this->assertSame(2, $this->listRowCount(), 'invalid sort must fall back, not drop rows');

        // Fallback is title asc: Alpha before Beta.
        $html = (string) $this->client->getResponse()->getContent();
        $this->assertLessThan(strpos($html, 'Beta Reading Spot'), strpos($html, 'Alpha Reading Spot'));
    }

    #[DataProvider('sqlAndWildcardPayloads')]
    public function testListFilterParamsRejectInjection(string $payload): void
    {
        $this->seedTwo();

        $params = [];
        foreach (['acc', 'status', 'type', 'mob', 'osm', 'minRating', 'page', 'perPage', 'userLat', 'userLon'] as $key) {
            $params[$key] = $payload;
        }
        $this->client->request('GET', '/list/fragment', $params);
        $this->assertNoServerError();
    }

    public function testListFilterTokenInjectionIsIntersectedWithWhitelist(): void
    {
        $this->seedTwo();

        // A token set of only junk means "none selected" on that dimension → 0 rows,
        // never "all rows" through an OR-injection.
        $this->client->request('GET', '/list/fragment', ['type' => "bookcase') OR 1=1 --"]);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->listRowCount());
    }

    public function testFilteredExportRejectsInjection(): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/api/bookcase/export', [
            'filtered' => '1',
            'q' => "' OR 1=1 --",
            'sort' => 'title DESC, (SELECT 1)',
            'dir' => '; DROP TABLE bookcase',
        ]);
        $this->assertResponseIsSuccessful();
        $body = (string) $this->client->getInternalResponse()->getContent();
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertSame(0, $data['count']);
        $this->assertSame([], $data['bookcases']);
    }

    // ── /api/bookcase/search ──────────────────────────────────────────────

    #[DataProvider('sqlPayloads')]
    public function testSearchPayloadDoesNotWidenResults(string $payload): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/api/bookcase/search', ['q' => $payload]);
        $this->assertResponseIsSuccessful();
        $this->assertNoServerError();
        $this->assertSame([], $this->json(), "search q=$payload must match nothing");
    }

    // ── /api/bookcase/ bounding box ───────────────────────────────────────

    #[DataProvider('sqlPayloads')]
    public function testBoundingBoxPayloadsAreCastNotInterpolated(string $payload): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/api/bookcase/', [
            'latMin' => $payload, 'latMax' => $payload, 'lonMin' => $payload, 'lonMax' => $payload,
            'limit' => $payload, 'offset' => $payload,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertNoServerError();
        $this->assertSame([], $this->json()['markers'], 'junk bbox must not match the seeded rows');
    }

    #[DataProvider('sqlPayloads')]
    public function testApiV1BoundingBoxPayloadsAreCastNotInterpolated(string $payload): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/api/v1/bookcases', [
            'latMin' => $payload, 'latMax' => $payload, 'lonMin' => $payload, 'lonMax' => $payload,
            'limit' => $payload, 'offset' => $payload,
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertNoServerError();
        $this->assertSame([], $this->json()['markers']);
    }

    // ── Admin list filters ────────────────────────────────────────────────

    #[DataProvider('sqlPayloads')]
    public function testAdminUserSearchDoesNotWidenResults(string $payload): void
    {
        $admin = UserFactory::new()->admin()->create(['username' => 'rootadmin', 'email' => 'root@example.com']);
        UserFactory::createOne(['username' => 'someone', 'email' => 'someone@example.com']);
        $this->client->loginUser($admin);

        $this->client->request('GET', '/admin/users', ['q' => $payload, 'page' => $payload]);
        $this->assertNoServerError();
        if ($this->client->getResponse()->getStatusCode() === 200) {
            $html = (string) $this->client->getResponse()->getContent();
            $this->assertStringNotContainsString('someone@example.com', $html, 'payload must not match all users');
        }
    }

    #[DataProvider('sqlPayloads')]
    public function testAdminApiUsageFiltersDoNotWidenResults(string $payload): void
    {
        $admin = UserFactory::new()->admin()->create();
        $this->client->loginUser($admin);

        $log = new ApiUsageLog();
        $log->method = 'GET';
        $log->path = '/api/v1/bookcases/secret-marker-path';
        $log->routeName = 'api_v1_bookcases_list';
        $log->statusCode = 200;
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($log);
        $em->flush();

        $this->client->request('GET', '/admin/api-usage', [
            'q' => $payload,
            'method' => $payload,
            'application' => $payload,
            'page' => '1',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertNoServerError();
        $this->assertStringNotContainsString('secret-marker-path', (string) $this->client->getResponse()->getContent());
    }

    // ── LIKE wildcards (documentation, not a security boundary) ───────────

    /**
     * Records current behaviour of `%` / `_` in the free-text search: the repos
     * do not escape LIKE wildcards, so `%` matches every row. That is not an
     * injection (the value is still a bound parameter), just a broad search.
     */
    public function testLikeWildcardsAreBoundParametersNotSql(): void
    {
        $this->seedTwo();

        $this->client->request('GET', '/api/bookcase/search', ['q' => '%%']);
        $this->assertResponseIsSuccessful();
        $this->assertLessThanOrEqual(8, count($this->json()), 'search result cap still applies');

        $this->client->request('GET', '/list/fragment', ['q' => '%']);
        $this->assertResponseIsSuccessful();
        $this->assertNoServerError();
    }
}

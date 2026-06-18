<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Tests\Factory\BookcaseFactory;
use PHPUnit\Framework\Attributes\DataProvider;

final class IndexControllerTest extends FunctionalTestCase
{
    public function testHomepageReturns200(): void
    {
        $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
    }

    public function testLegacyMapPathServesTheMapWithoutRedirect(): void
    {
        // Legacy backlinks point at /map; it must serve the homepage directly
        // (HTTP 200, no redirect) so the inbound links keep their value.
        $crawler = $this->client->request('GET', '/map');

        $this->assertResponseIsSuccessful();
        $this->assertSame(200, $this->client->getResponse()->getStatusCode());
        $this->assertGreaterThan(0, $crawler->filter('#map')->count());
    }

    public function testLegacyMapPathCanonicalisesToHomepage(): void
    {
        // Both / and /map render the same page, so /map must declare the homepage
        // as its canonical URL to consolidate SEO signals (no duplicate content).
        $html = $this->client->request('GET', '/map')->html();

        $this->assertMatchesRegularExpression('#<link rel="canonical" href="https?://[^/]+/">#', $html);
    }

    public function testHomepageExposesSeoTags(): void
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        // Canonical, keyword meta, Open Graph and structured data for search engines.
        $this->assertStringContainsString('<link rel="canonical"', $html);
        $this->assertStringContainsString('name="keywords"', $html);
        $this->assertStringContainsString('property="og:title"', $html);
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"@type": "WebSite"', $html);

        // A real (screen-reader) H1 gives crawlers keyword-bearing text the map can't.
        $this->assertSame(1, $crawler->filter('h1')->count());
        $this->assertNotSame('', trim($crawler->filter('h1')->text()));
    }

    public function testHomepageExposesPwaTags(): void
    {
        $html = $this->client->request('GET', '/')->html();
        $this->assertResponseIsSuccessful();

        // Installable PWA wiring lives in base.html.twig, so it ships on every page.
        $this->assertStringContainsString('rel="manifest" href="/manifest.webmanifest"', $html);
        $this->assertStringContainsString('name="theme-color"', $html);
        $this->assertStringContainsString('/icons/apple-touch-icon.png', $html);
        $this->assertStringContainsString("navigator.serviceWorker.register('/sw.js'", $html);

        // The "Install app" button ships hidden; JS reveals it on `beforeinstallprompt`.
        $this->assertStringContainsString('data-install-button', $html);
        $this->assertStringContainsString('beforeinstallprompt', $html);
    }

    public function testListReturns200(): void
    {
        $this->client->request('GET', '/list');
        $this->assertResponseIsSuccessful();
    }

    public function testListShowsExportTermsDialogWithMandatoryLinks(): void
    {
        $this->client->request('GET', '/list');
        $this->assertResponseIsSuccessful();
        $html = $this->client->getResponse()->getContent();

        // Export links are gated behind the usage-terms confirmation dialog.
        $this->assertStringContainsString('data-export-download', $html);
        $this->assertStringContainsString('id="exportTermsModal"', $html);
        $this->assertStringContainsString('export-terms', $html);

        // The ODbL licence link and the mandatory OpenStreetMap copyright link must be present.
        $this->assertStringContainsString('https://opendatacommons.org/licenses/odbl/', $html);
        $this->assertStringContainsString('https://www.openstreetmap.org/copyright', $html);
    }

    public function testListFragmentReturns200(): void
    {
        $this->client->request('GET', '/list/fragment');
        $this->assertResponseIsSuccessful();
    }

    public function testListFragmentFiltersByQuery(): void
    {
        BookcaseFactory::createOne(['title' => 'Alpha Reading Spot']);
        BookcaseFactory::createOne(['title' => 'Beta Reading Spot']);

        $crawler = $this->client->request('GET', '/list/fragment', ['q' => 'Alpha']);
        $this->assertResponseIsSuccessful();

        $html = $crawler->html();
        $this->assertStringContainsString('Alpha Reading Spot', $html);
        $this->assertStringNotContainsString('Beta Reading Spot', $html);
    }

    public function testListFragmentSortAscendingVsDescending(): void
    {
        BookcaseFactory::createOne(['title' => 'Aaa First Title']);
        BookcaseFactory::createOne(['title' => 'Zzz Last Title']);

        $ascHtml = $this->client
            ->request('GET', '/list/fragment', ['sort' => 'title', 'dir' => 'asc'])
            ->html();
        $this->assertResponseIsSuccessful();

        $descHtml = $this->client
            ->request('GET', '/list/fragment', ['sort' => 'title', 'dir' => 'desc'])
            ->html();
        $this->assertResponseIsSuccessful();

        // In ascending order "Aaa" comes before "Zzz"; descending flips it.
        $this->assertLessThan(
            strpos($ascHtml, 'Zzz Last Title'),
            strpos($ascHtml, 'Aaa First Title'),
            'asc: Aaa should appear before Zzz',
        );
        $this->assertLessThan(
            strpos($descHtml, 'Aaa First Title'),
            strpos($descHtml, 'Zzz Last Title'),
            'desc: Zzz should appear before Aaa',
        );
    }

    public function testListFragmentNewestSortExcludesOsmImports(): void
    {
        BookcaseFactory::createOne(['title' => 'Community Added Spot']);
        BookcaseFactory::new()->osm('n999')->create(['title' => 'Osm Imported Spot']);

        $html = $this->client
            ->request('GET', '/list/fragment', ['sort' => 'newest', 'dir' => 'desc'])
            ->html();
        $this->assertResponseIsSuccessful();

        $this->assertStringContainsString('Community Added Spot', $html);
        $this->assertStringNotContainsString('Osm Imported Spot', $html);
    }

    public function testListFragmentPaginationLimitsRows(): void
    {
        BookcaseFactory::createMany(15, ['title' => 'Pageable Bookcase']);

        // perPage=10 → page 1 shows 10 of the 15 matches.
        $page1 = $this->client
            ->request('GET', '/list/fragment', ['q' => 'Pageable', 'perPage' => 10, 'page' => 1])
            ->html();
        $this->assertResponseIsSuccessful();
        $this->assertSame(10, substr_count($page1, 'Pageable Bookcase'));

        // page 2 shows the remaining 5.
        $page2 = $this->client
            ->request('GET', '/list/fragment', ['q' => 'Pageable', 'perPage' => 10, 'page' => 2])
            ->html();
        $this->assertResponseIsSuccessful();
        $this->assertSame(5, substr_count($page2, 'Pageable Bookcase'));
    }

    public function testDeepLinkForExistingBookcaseReturns200(): void
    {
        $bookcase = BookcaseFactory::createOne();

        $this->client->request('GET', '/bookcase/'.$bookcase->id);
        $this->assertResponseIsSuccessful();
    }

    /**
     * Documented behavior (IndexController::showBookcase): "Unknown ids just fall
     * back to the map." The deep-link route does NOT 404 — it renders the map with
     * a null initialBookcase regardless of whether the id resolves.
     */
    #[DataProvider('garbageIdProvider')]
    public function testDeepLinkForUnknownIdFallsBackToMap(string $id): void
    {
        $this->client->request('GET', '/bookcase/'.$id);
        $this->assertResponseIsSuccessful();
    }

    /** @return array<string, array{string}> */
    public static function garbageIdProvider(): array
    {
        return [
            'non-ulid garbage' => ['not-a-real-id'],
            // A syntactically valid ULID that does not exist in the DB.
            'valid-but-missing ulid' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'],
        ];
    }

    public function testShortLinkResolvesByShortCode(): void
    {
        BookcaseFactory::createOne(['shortCode' => 'ABC123']);

        $this->client->request('GET', '/s/ABC123');
        $this->assertResponseIsSuccessful();
    }

    public function testShortLinkResolvesByLegacyIdFallback(): void
    {
        // shortCode must stay numeric-free so the ctype_digit legacy fallback runs.
        BookcaseFactory::createOne(['shortCode' => 'XYZabc', 'legacyId' => 4242]);

        // Numeric code with no matching shortCode → falls back to legacyId, renders the map (200).
        $this->client->request('GET', '/s/4242');
        $this->assertResponseIsSuccessful();
    }

    public function testShortLinkUnknownCodeStillReturns200(): void
    {
        // Controller renders the map even when the code resolves to nothing.
        $this->client->request('GET', '/s/nope99');
        $this->assertResponseIsSuccessful();
    }
}

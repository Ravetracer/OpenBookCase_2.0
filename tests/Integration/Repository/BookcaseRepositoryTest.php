<?php declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Bookcase;
use App\Enums\AccessibilityLevel;
use App\Model\BookcaseFilter;
use App\Repository\BookcaseRepository;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\RatingFactory;
use App\Tests\Factory\WishlistItemFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookcaseRepositoryTest extends KernelTestCase
{
    private function repo(): BookcaseRepository
    {
        return self::getContainer()->get(BookcaseRepository::class);
    }

    public function testFindByBoundingBoxReturnsOnlyInsideBox(): void
    {
        $inside = BookcaseFactory::new()->at(52.5, 13.4)->create();   // Berlin
        BookcaseFactory::new()->at(48.1, 11.6)->create();             // Munich (outside)

        $result = $this->repo()->findByBoundingBox(52.0, 53.0, 13.0, 14.0);

        $ids = array_map(fn (Bookcase $b) => (string) $b->id, $result);
        $this->assertContains((string) $inside->id, $ids);
        $this->assertCount(1, $result);
    }

    public function testCountByBoundingBox(): void
    {
        BookcaseFactory::new()->at(52.5, 13.4)->create();
        BookcaseFactory::new()->at(52.6, 13.5)->create();
        BookcaseFactory::new()->at(10.0, 10.0)->create();

        $this->assertSame(2, $this->repo()->countByBoundingBox(52.0, 53.0, 13.0, 14.0));
    }

    public function testFindByBoundingBoxLightShapeAndAggregates(): void
    {
        $bookcase = BookcaseFactory::new()->at(52.5, 13.4)->create();
        RatingFactory::createOne(['bookcase' => $bookcase, 'value' => 4]);
        RatingFactory::createOne(['bookcase' => $bookcase, 'value' => 2]);
        WishlistItemFactory::new()->open()->create(['bookcase' => $bookcase]);
        WishlistItemFactory::new()->dropped()->create(['bookcase' => $bookcase]);

        $rows = $this->repo()->findByBoundingBoxLight(52.0, 53.0, 13.0, 14.0);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertArrayHasKey('id', $row);
        $this->assertArrayHasKey('latitude', $row);
        $this->assertSame(2, (int) $row['ratingCount'], 'two ratings, not inflated by the wishlist join');
        $this->assertSame(3.0, (float) $row['ratingAverage']);
        $this->assertSame(1, (int) $row['openWishlistCount'], 'only the OPEN wish counts');
    }

    public function testFindByBoundingBoxLightPaginates(): void
    {
        BookcaseFactory::new()->many(5)->create(fn () => ['position' => (function () {
            $p = new \App\Entity\Embeddables\Position();
            $p->latitude = 52.5;
            $p->longitude = 13.4;
            return $p;
        })()]);

        $page1 = $this->repo()->findByBoundingBoxLight(52.0, 53.0, 13.0, 14.0, 2, 0);
        $page2 = $this->repo()->findByBoundingBoxLight(52.0, 53.0, 13.0, 14.0, 2, 2);

        $this->assertCount(2, $page1);
        $this->assertCount(2, $page2);
        $this->assertNotEquals(
            array_column($page1, 'id'),
            array_column($page2, 'id'),
            'pages must not overlap'
        );
    }

    public function testSearchByTitleIsCaseInsensitiveSubstring(): void
    {
        BookcaseFactory::createOne(['title' => 'Rosengarten Bookcase']);
        BookcaseFactory::createOne(['title' => 'Unrelated Spot']);

        // Upper-case query must still match the lower-cased title substring.
        $hit = $this->repo()->searchByTitle('ROSENGARTEN');
        $this->assertCount(1, $hit);
        $this->assertSame('Rosengarten Bookcase', $hit[0]['title']);

        $this->assertCount(0, $this->repo()->searchByTitle('nonexistent-term'));
    }

    public function testCountFilteredAndPaginatedSearch(): void
    {
        BookcaseFactory::createOne(['title' => 'Alpha Library']);
        BookcaseFactory::createOne(['title' => 'Beta Library']);
        BookcaseFactory::createOne(['title' => 'Gamma Hut']);

        $this->assertSame(2, $this->repo()->countFiltered('library', new BookcaseFilter()));
        $this->assertSame(3, $this->repo()->countFiltered(null, new BookcaseFilter()));

        $page = $this->repo()->findFilteredPaginated('library', 'title', 'asc', null, null, null, 10, 0, new BookcaseFilter());
        $titles = array_map(fn (Bookcase $b) => $b->title, $page);
        $this->assertSame(['Alpha Library', 'Beta Library'], $titles);
    }

    public function testDistanceSortOrdersNearestFirst(): void
    {
        $near = BookcaseFactory::new()->at(52.50, 13.40)->create(['title' => 'Near']);
        $mid  = BookcaseFactory::new()->at(52.60, 13.40)->create(['title' => 'Mid']);
        $far  = BookcaseFactory::new()->at(53.50, 13.40)->create(['title' => 'Far']);

        $userLat = 52.50;
        $cosLat = cos(deg2rad($userLat));
        $ordered = $this->repo()->findFilteredPaginated(null, 'distance', 'asc', $userLat, 13.40, $cosLat, 10, 0, new BookcaseFilter());

        $titles = array_map(fn (Bookcase $b) => $b->title, $ordered);
        $this->assertSame(['Near', 'Mid', 'Far'], $titles);
    }

    public function testNewestSortOrdersByCreationAndExcludesOsmImports(): void
    {
        // Created in order → time-ordered ULIDs, so "newest" desc must reverse them.
        $first  = BookcaseFactory::createOne(['title' => 'First']);
        $second = BookcaseFactory::createOne(['title' => 'Second']);
        $osm    = BookcaseFactory::new()->osm('n123')->create(['title' => 'OSM Import']);

        // The OSM "without" provenance filter excludes the OSM-sourced row.
        $this->assertSame(3, $this->repo()->countFiltered(null, new BookcaseFilter()));
        $this->assertSame(2, $this->repo()->countFiltered(null, new BookcaseFilter(osm: 'without')));

        $newest = $this->repo()->findFilteredPaginated(null, 'newest', 'desc', null, null, null, 10, 0, new BookcaseFilter(osm: 'without'));
        $titles = array_map(fn (Bookcase $b) => $b->title, $newest);

        $this->assertNotContains('OSM Import', $titles, 'OSM imports are not community additions');
        $this->assertSame(['Second', 'First'], $titles);
    }

    public function testOsmProvenanceFilterModes(): void
    {
        BookcaseFactory::createOne(['title' => 'Community A']);
        BookcaseFactory::createOne(['title' => 'Community B']);
        BookcaseFactory::new()->osm('n777')->create(['title' => 'OSM One']);

        $this->assertSame(3, $this->repo()->countFiltered(null, new BookcaseFilter(osm: 'with')), 'with = all');
        $this->assertSame(1, $this->repo()->countFiltered(null, new BookcaseFilter(osm: 'only')), 'only = OSM imports');
        $this->assertSame(2, $this->repo()->countFiltered(null, new BookcaseFilter(osm: 'without')), 'without = community only');

        $only = $this->repo()->findFilteredPaginated(null, 'title', 'asc', null, null, null, 10, 0, new BookcaseFilter(osm: 'only'));
        $this->assertSame(['OSM One'], array_map(fn (Bookcase $b) => $b->title, $only));
    }

    public function testFilterByTypeStatusAndAccessibility(): void
    {
        BookcaseFactory::new()->withAccessibility(AccessibilityLevel::Full)->create(['title' => 'Green Case']);
        BookcaseFactory::new()->inactive()->create(['title' => 'Closed Case']);
        BookcaseFactory::new()->givebox()->create(['title' => 'A Givebox']);
        BookcaseFactory::createOne(['title' => 'Plain Case']);

        // Only giveboxes.
        $giveboxes = $this->repo()->findFilteredPaginated(
            null, 'title', 'asc', null, null, null, 10, 0,
            new BookcaseFilter(types: ['givebox']),
        );
        $this->assertSame(['A Givebox'], array_map(fn (Bookcase $b) => $b->title, $giveboxes));

        // Only inactive entries.
        $this->assertSame(1, $this->repo()->countFiltered(null, new BookcaseFilter(status: ['inactive'])));

        // Only the fully-accessible (green) entry.
        $green = $this->repo()->findFilteredPaginated(
            null, 'title', 'asc', null, null, null, 10, 0,
            new BookcaseFilter(accessibility: ['green']),
        );
        $this->assertSame(['Green Case'], array_map(fn (Bookcase $b) => $b->title, $green));

        // 'unset' = no accessibility level recorded (everything but the green one).
        $this->assertSame(3, $this->repo()->countFiltered(null, new BookcaseFilter(accessibility: ['unset'])));
    }

    public function testFilterByMinimumRatingAndOpenWishes(): void
    {
        $loved = BookcaseFactory::createOne(['title' => 'Loved']);
        RatingFactory::createOne(['bookcase' => $loved, 'value' => 5]);
        RatingFactory::createOne(['bookcase' => $loved, 'value' => 3]); // avg 4.0

        $meh = BookcaseFactory::createOne(['title' => 'Meh']);
        RatingFactory::createOne(['bookcase' => $meh, 'value' => 2]); // avg 2.0

        $wished = BookcaseFactory::createOne(['title' => 'Wished']);
        WishlistItemFactory::new()->open()->create(['bookcase' => $wished]);

        // Average rating ≥ 4 → only "Loved" (unrated entries have no average).
        $rated = $this->repo()->findFilteredPaginated(
            null, 'title', 'asc', null, null, null, 10, 0,
            new BookcaseFilter(minRating: 4),
        );
        $this->assertSame(['Loved'], array_map(fn (Bookcase $b) => $b->title, $rated));

        // Has at least one open wish → only "Wished".
        $wishes = $this->repo()->findFilteredPaginated(
            null, 'title', 'asc', null, null, null, 10, 0,
            new BookcaseFilter(wishlist: true),
        );
        $this->assertSame(['Wished'], array_map(fn (Bookcase $b) => $b->title, $wishes));
    }

    public function testEmptyCategorySelectionMatchesNothing(): void
    {
        BookcaseFactory::createMany(2);

        // No type tokens selected → the user unchecked everything → no rows.
        $this->assertSame(0, $this->repo()->countFiltered(null, new BookcaseFilter(types: [])));
    }

    public function testGetCreatedAtDerivesFromUlid(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $this->assertNotNull($bookcase->getCreatedAt());
        $this->assertSame(
            $bookcase->id->getDateTime()->getTimestamp(),
            $bookcase->getCreatedAt()->getTimestamp(),
        );
    }

    public function testFindOneWithRelationsResolvesValidUlidAndRejectsGarbage(): void
    {
        $bookcase = BookcaseFactory::createOne();

        $found = $this->repo()->findOneWithRelations((string) $bookcase->id);
        $this->assertNotNull($found);
        $this->assertSame((string) $bookcase->id, (string) $found->id);

        $this->assertNull($this->repo()->findOneWithRelations('not-a-ulid'));
    }
}

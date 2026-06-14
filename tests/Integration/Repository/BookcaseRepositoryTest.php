<?php declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Bookcase;
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

        $this->assertSame(2, $this->repo()->countFiltered('library'));
        $this->assertSame(3, $this->repo()->countFiltered(null));

        $page = $this->repo()->findFilteredPaginated('library', 'title', 'asc', null, null, null, 10, 0);
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
        $ordered = $this->repo()->findFilteredPaginated(null, 'distance', 'asc', $userLat, 13.40, $cosLat, 10, 0);

        $titles = array_map(fn (Bookcase $b) => $b->title, $ordered);
        $this->assertSame(['Near', 'Mid', 'Far'], $titles);
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

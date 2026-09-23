<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Repository\BookcaseRepository;
use App\Repository\WatchlistItemRepository;
use App\Service\BookcaseListService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookcaseListServiceTest extends TestCase
{
    /** @return iterable<string, array{float, float, float, float, float}> */
    public static function distances(): iterable
    {
        yield 'same point' => [52.52, 13.405, 52.52, 13.405, 0.0];
        yield 'Berlin → Munich' => [52.52, 13.405, 48.137, 11.575, 504.0];
        yield 'one degree of latitude' => [0.0, 0.0, 1.0, 0.0, 111.19];
        yield 'antipodes' => [0.0, 0.0, 0.0, 180.0, 20015.09];
    }

    #[DataProvider('distances')]
    public function testHaversineKm(float $lat1, float $lon1, float $lat2, float $lon2, float $expectedKm): void
    {
        $service = new BookcaseListService(
            $this->createStub(BookcaseRepository::class),
            $this->createStub(WatchlistItemRepository::class),
        );

        $this->assertEqualsWithDelta($expectedKm, $service->haversineKm($lat1, $lon1, $lat2, $lon2), 1.0);
    }

    public function testAnonymousVisitorWatchesNothing(): void
    {
        $watchlist = $this->createMock(WatchlistItemRepository::class);
        $watchlist->expects($this->never())->method('findWatchedBookcaseIds');

        $service = new BookcaseListService($this->createStub(BookcaseRepository::class), $watchlist);

        $this->assertSame([], $service->watchedIds(null));
    }
}

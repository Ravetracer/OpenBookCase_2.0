<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Repository\WatchlistItemRepository;
use App\Service\WatchlistService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class WatchlistServiceTest extends KernelTestCase
{
    public function testWatchAndUnwatchAreIdempotent(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $user = UserFactory::createOne();
        $service = self::getContainer()->get(WatchlistService::class);
        $items = self::getContainer()->get(WatchlistItemRepository::class);

        $this->assertFalse($service->isWatching($bookcase, $user));
        $this->assertFalse($service->isWatching($bookcase, null));

        $service->watch($bookcase, $user);
        $service->watch($bookcase, $user);
        $this->assertTrue($service->isWatching($bookcase, $user));
        $this->assertSame(1, $items->count([]));

        $service->unwatch($bookcase, $user);
        $service->unwatch($bookcase, $user);
        $this->assertFalse($service->isWatching($bookcase, $user));
        $this->assertSame(0, $items->count([]));
    }
}

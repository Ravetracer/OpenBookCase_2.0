<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Message;
use App\Entity\WishlistItem;
use App\Enums\WishlistItemStatus;
use App\Service\WishlistService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;
use App\Tests\Factory\WishlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Wish creation (validated before persisting), the drop / pick-up / not-found
 * hand-off with its notifications, and cancelling.
 */
final class WishlistServiceTest extends KernelTestCase
{
    public function testNewItemTrimsOptionalFieldsToNull(): void
    {
        $item = $this->service()->newItem(BookcaseFactory::createOne(), UserFactory::createOne(), 'Dune', '  ', ' 978-3 ', '');

        $this->assertSame(WishlistItemStatus::Open, $item->status);
        $this->assertNull($item->author);
        $this->assertSame('978-3', $item->isbn);
        $this->assertNull($item->misc);
    }

    public function testStorePersistsValidWish(): void
    {
        $item = $this->service()->newItem(BookcaseFactory::createOne(), UserFactory::createOne(), 'Dune', 'Herbert', null, null);

        $this->assertSame([], $this->service()->store($item));
        $this->assertNotNull($item->id);
        $this->assertSame(1, $this->rows(WishlistItem::class));
    }

    public function testStoreRejectsOverlongFieldsWithoutPersisting(): void
    {
        $item = $this->service()->newItem(BookcaseFactory::createOne(), UserFactory::createOne(), str_repeat('W', 256), null, str_repeat('9', 256), null);

        $fields = array_column($this->service()->store($item), 'field');

        $this->assertSame(['title', 'isbn'], $fields);
        $this->assertSame(0, $this->rows(WishlistItem::class));
    }

    public function testNewWishNotifiesWatchersExceptCreator(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $creator = UserFactory::createOne();
        $watcher = UserFactory::createOne();
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => $creator]);
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => $watcher]);

        $item = WishlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => $creator]);
        $this->service()->notifyWatchersOfNewWish($item);

        $messages = $this->em()->getRepository(Message::class)->findAll();
        $this->assertCount(1, $messages);
        $this->assertSame((string) $watcher->id, (string) $messages[0]->recipient->id);
    }

    public function testDropThenFulfillNotifiesBothSides(): void
    {
        $requester = UserFactory::createOne();
        $dropper = UserFactory::createOne();
        $item = WishlistItemFactory::createOne(['user' => $requester]);

        $this->assertNull($this->service()->changeStatus($item, $dropper, 'drop'));
        $this->assertSame(WishlistItemStatus::Dropped, $item->status);
        $this->assertSame($dropper, $item->droppedBy);

        $this->assertNull($this->service()->changeStatus($item, $requester, 'fulfill'));
        $this->assertSame(WishlistItemStatus::Fulfilled, $item->status);
        $this->assertSame(2, $this->rows(Message::class), 'requester told to collect, dropper thanked');
    }

    public function testNotFoundReopensAndAppendsComment(): void
    {
        $requester = UserFactory::createOne();
        $dropper = UserFactory::createOne();
        $item = WishlistItemFactory::new()->dropped()->create(['user' => $requester, 'droppedBy' => $dropper]);

        $this->assertNull($this->service()->changeStatus($item, $requester, 'notfound', ' shelf empty '));

        $this->assertSame(WishlistItemStatus::Open, $item->status);
        $this->assertNull($item->droppedBy);
        $message = $this->em()->getRepository(Message::class)->findOneBy([]);
        $this->assertStringContainsString('shelf empty', (string) $message->body);
    }

    public function testChangeStatusErrors(): void
    {
        $requester = UserFactory::createOne();
        $other = UserFactory::createOne();
        $open = WishlistItemFactory::createOne(['user' => $requester]);
        $dropped = WishlistItemFactory::new()->dropped()->create(['user' => $requester]);

        $this->assertSame(['error' => 'flash.wish_cannot_drop', 'status' => 409], $this->service()->changeStatus($dropped, $other, 'drop'));
        $this->assertSame(['error' => 'flash.only_requester_pickup', 'status' => 403], $this->service()->changeStatus($dropped, $other, 'fulfill'));
        $this->assertSame(['error' => 'flash.not_awaiting_pickup', 'status' => 409], $this->service()->changeStatus($open, $requester, 'fulfill'));
        $this->assertSame(['error' => 'flash.only_requester_missing', 'status' => 403], $this->service()->changeStatus($dropped, $other, 'notfound'));
        $this->assertSame(['error' => 'flash.unknown_action', 'status' => 400], $this->service()->changeStatus($open, $requester, 'bogus'));
        $this->assertSame(0, $this->rows(Message::class));
    }

    public function testCancelOnlyByRequesterWhileOpen(): void
    {
        $requester = UserFactory::createOne();
        $open = WishlistItemFactory::createOne(['user' => $requester]);
        $dropped = WishlistItemFactory::new()->dropped()->create(['user' => $requester]);

        $this->assertSame(['error' => 'flash.only_requester_cancel', 'status' => 403], $this->service()->cancel($open, UserFactory::createOne()));
        $this->assertSame(['error' => 'flash.only_open_cancel', 'status' => 409], $this->service()->cancel($dropped, $requester));
        $this->assertNull($this->service()->cancel($open, $requester));
        $this->assertSame(1, $this->rows(WishlistItem::class));
    }

    private function service(): WishlistService
    {
        return self::getContainer()->get(WishlistService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @param class-string $class */
    private function rows(string $class): int
    {
        return $this->em()->getRepository($class)->count([]);
    }
}

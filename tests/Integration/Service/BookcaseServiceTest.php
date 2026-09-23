<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Bookcase;
use App\Entity\DeletedBookcase;
use App\Entity\Message;
use App\Enums\MessageType;
use App\Enums\NotificationChannel;
use App\Service\BookcaseService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class BookcaseServiceTest extends KernelTestCase
{
    public function testCreateAssignsShortCodeAndPersists(): void
    {
        $bookcase = new Bookcase();
        $bookcase->title = 'Fresh entry';
        $bookcase->position->latitude = 52.5;
        $bookcase->position->longitude = 13.4;

        $this->service()->create($bookcase);

        $this->assertNotNull($bookcase->id);
        $this->assertMatchesRegularExpression('/^[0-9A-Za-z]{6}$/', (string) $bookcase->shortCode);
        $this->em()->clear();
        $this->assertNotNull($this->em()->getRepository(Bookcase::class)->find($bookcase->id));
    }

    public function testArchiveAndDeleteKeepsSnapshotAndRemovesEntry(): void
    {
        $bookcase = BookcaseFactory::createOne(['title' => 'Gone soon']);
        $id = $bookcase->id;
        $user = UserFactory::createOne(['username' => 'deleter']);

        $this->service()->archiveAndDelete($bookcase, 'Demolished', $user);

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(Bookcase::class)->find($id));
        $backup = $this->em()->getRepository(DeletedBookcase::class)->findOneBy(['originalId' => (string) $id]);
        $this->assertNotNull($backup);
        $this->assertSame('Gone soon', $backup->title);
        $this->assertSame('Demolished', $backup->reason);
        $this->assertSame('deleter', $backup->deletedBy);
        $this->assertSame('Gone soon', $backup->payload['title'] ?? null);
    }

    public function testArchiveWithoutUserLeavesDeletedByEmpty(): void
    {
        $bookcase = BookcaseFactory::createOne();
        $id = (string) $bookcase->id;

        $this->service()->archiveAndDelete($bookcase, 'Spam', null);

        $backup = $this->em()->getRepository(DeletedBookcase::class)->findOneBy(['originalId' => $id]);
        $this->assertNull($backup->deletedBy);
    }

    public function testChangedFieldsListsOnlyDifferingLabels(): void
    {
        $bookcase = BookcaseFactory::createOne(['title' => 'Before', 'comment' => 'same']);
        $before = $this->service()->snapshot($bookcase);

        $bookcase->title = 'After';
        $bookcase->isMobile = true;

        $this->assertSame(['Title', 'Mobility'], $this->service()->changedFields($before, $this->service()->snapshot($bookcase)));
        $this->assertSame([], $this->service()->changedFields($before, $before));
    }

    public function testSaveEditedClearsProvisionalTitleAndNotifiesOtherWatchers(): void
    {
        $bookcase = BookcaseFactory::createOne(['title' => 'Public bookcase', 'titleProvisional' => true]);
        $editor = UserFactory::createOne(['username' => 'editor', 'notificationChannel' => NotificationChannel::Internal]);
        $watcher = UserFactory::createOne(['notificationChannel' => NotificationChannel::Internal]);
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => $editor]);
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => $watcher]);

        $before = $this->service()->snapshot($bookcase);
        $bookcase->title = 'Corner Bookcase';
        $this->service()->saveEdited($bookcase, $before, $editor);

        $this->assertFalse($bookcase->titleProvisional);
        $messages = $this->em()->getRepository(Message::class)->findBy(['type' => MessageType::BookcaseChanged]);
        $this->assertCount(1, $messages, 'only the non-editing watcher is notified');
        $this->assertSame((string) $watcher->id, (string) $messages[0]->recipient->id);
        $this->assertStringContainsString('Title', (string) $messages[0]->body);
    }

    public function testSaveEditedWithoutChangesSendsNothing(): void
    {
        $bookcase = BookcaseFactory::createOne();
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => UserFactory::createOne(['notificationChannel' => NotificationChannel::Internal])]);

        $this->service()->saveEdited($bookcase, $this->service()->snapshot($bookcase), null);

        $this->assertSame(0, $this->em()->getRepository(Message::class)->count([]));
    }

    public function testMoveNotifiesWatchers(): void
    {
        $bookcase = BookcaseFactory::createOne();
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => UserFactory::createOne(['notificationChannel' => NotificationChannel::Internal])]);

        $this->service()->move($bookcase, 51.0, 9.0, UserFactory::createOne());
        $this->assertSame(9.0, $bookcase->position->longitude);
        $this->assertSame(1, $this->em()->getRepository(Message::class)->count([]));
    }

    private function service(): BookcaseService
    {
        return self::getContainer()->get(BookcaseService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}

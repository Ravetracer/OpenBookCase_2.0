<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Message;
use App\Entity\WishlistItem;
use App\Enums\WishlistItemStatus;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;
use App\Tests\Factory\WishlistItemFactory;
use Doctrine\ORM\EntityManagerInterface;

final class WishlistControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/wishlist  (add)
    // ---------------------------------------------------------------------

    public function testAddRequiresAuth(): void
    {
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist', ['title' => 'Dune']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testAddTitleRequired(): void
    {
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist', ['title' => '   ']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testAddCreatesOpenWishAndIncrementsCount(): void
    {
        $user = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;

        $this->client->request('POST', '/api/bookcase/' . $id . '/wishlist', [
            'title' => 'Dune',
            'author' => 'Frank Herbert',
        ]);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('success', $data['status']);
        $this->assertSame(1, $data['openCount']);

        $this->em()->clear();
        $items = $this->em()->getRepository(WishlistItem::class)->findBy(['bookcase' => $id]);
        $this->assertCount(1, $items);
        $this->assertSame('Dune', $items[0]->title);
        $this->assertSame('Frank Herbert', $items[0]->author);
        $this->assertSame(WishlistItemStatus::Open, $items[0]->status);
        $this->assertSame((string) $user->id, (string) $items[0]->user->id);

        // A second wish bumps the open count.
        $this->client->request('POST', '/api/bookcase/' . $id . '/wishlist', ['title' => 'Foundation']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(2, $this->json()['openCount']);
    }

    public function testAddNotifiesWatchersOfBookcase(): void
    {
        $watcher = UserFactory::createOne();
        $requester = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $id = (string) $bc->id;
        WatchlistItemFactory::createOne(['bookcase' => $bc, 'user' => $watcher]);

        $this->client->request('POST', '/api/bookcase/' . $id . '/wishlist', ['title' => 'Neuromancer']);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        // The watcher (not the requester) gets a message.
        $msgs = $this->em()->getRepository(Message::class)->findBy(['recipient' => $watcher->id]);
        $this->assertCount(1, $msgs, 'the watcher should be notified of the new wish');
        $this->assertCount(0, $this->em()->getRepository(Message::class)->findBy(['recipient' => $requester->id]));
    }

    // ---------------------------------------------------------------------
    // POST /api/bookcase/{id}/wishlist/{item}/status
    // ---------------------------------------------------------------------

    public function testStatusRequiresAuth(): void
    {
        $item = WishlistItemFactory::new()->open()->create();
        $this->client->request('POST', '/api/bookcase/' . $item->bookcase->id . '/wishlist/' . $item->id . '/status', ['action' => 'drop']);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testStatusUnknownActionRejected(): void
    {
        $this->loginAsUser();
        $item = WishlistItemFactory::new()->open()->create();
        $this->client->request('POST', '/api/bookcase/' . $item->bookcase->id . '/wishlist/' . $item->id . '/status', ['action' => 'frobnicate']);
        $this->assertResponseStatusCodeSame(400);
    }

    public function testDropByDonorNotifiesRequesterAndSetsDroppedBy(): void
    {
        $requester = UserFactory::createOne();
        $donor = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->open()->create([
            'bookcase' => $bc,
            'user' => $requester,
            'title' => 'The Hobbit',
        ]);
        $itemId = (string) $item->id;

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist/' . $itemId . '/status', ['action' => 'drop']);
        $this->assertResponseIsSuccessful();
        $data = $this->json();
        $this->assertSame('success', $data['status']);
        $this->assertSame(WishlistItemStatus::Dropped->value, $data['itemStatus']);
        // No longer open.
        $this->assertSame(0, $data['openCount']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(WishlistItem::class)->find($itemId);
        $this->assertSame(WishlistItemStatus::Dropped, $reloaded->status);
        $this->assertNotNull($reloaded->droppedBy);
        $this->assertSame((string) $donor->id, (string) $reloaded->droppedBy->id);

        // Requester is notified.
        $msgs = $this->em()->getRepository(Message::class)->findBy(['recipient' => $requester->id]);
        $this->assertCount(1, $msgs);
    }

    public function testDropOnNonOpenWishConflict(): void
    {
        $this->loginAsUser();
        $item = WishlistItemFactory::new()->dropped()->create();
        $this->client->request('POST', '/api/bookcase/' . $item->bookcase->id . '/wishlist/' . $item->id . '/status', ['action' => 'drop']);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testFulfillByRequesterMarksFulfilledAndThanksDropper(): void
    {
        $donor = UserFactory::createOne();
        $requester = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->dropped()->create([
            'bookcase' => $bc,
            'user' => $requester,
            'droppedBy' => $donor,
            'title' => 'Snow Crash',
        ]);
        $itemId = (string) $item->id;

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist/' . $itemId . '/status', ['action' => 'fulfill']);
        $this->assertResponseIsSuccessful();
        $this->assertSame(WishlistItemStatus::Fulfilled->value, $this->json()['itemStatus']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(WishlistItem::class)->find($itemId);
        $this->assertSame(WishlistItemStatus::Fulfilled, $reloaded->status);

        // The dropper is thanked.
        $this->assertCount(1, $this->em()->getRepository(Message::class)->findBy(['recipient' => $donor->id]));
    }

    public function testFulfillByNonRequesterForbidden(): void
    {
        $requester = UserFactory::createOne();
        $this->loginAsUser(); // a different, logged-in user
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->dropped()->create([
            'bookcase' => $bc,
            'user' => $requester,
            'droppedBy' => UserFactory::createOne(),
        ]);

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist/' . $item->id . '/status', ['action' => 'fulfill']);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testFulfillWhenNotDroppedConflict(): void
    {
        $requester = $this->loginAsUser();
        $item = WishlistItemFactory::new()->open()->create(['user' => $requester]);
        $this->client->request('POST', '/api/bookcase/' . $item->bookcase->id . '/wishlist/' . $item->id . '/status', ['action' => 'fulfill']);
        $this->assertResponseStatusCodeSame(409);
    }

    public function testNotFoundByRequesterReopensAndNotifiesDropper(): void
    {
        $donor = UserFactory::createOne();
        $requester = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->dropped()->create([
            'bookcase' => $bc,
            'user' => $requester,
            'droppedBy' => $donor,
        ]);
        $itemId = (string) $item->id;

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/wishlist/' . $itemId . '/status', [
            'action' => 'notfound',
            'comment' => 'shelf was empty',
        ]);
        $this->assertResponseIsSuccessful();
        // Reopened.
        $this->assertSame(WishlistItemStatus::Open->value, $this->json()['itemStatus']);
        $this->assertSame(1, $this->json()['openCount']);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(WishlistItem::class)->find($itemId);
        $this->assertSame(WishlistItemStatus::Open, $reloaded->status);
        $this->assertNull($reloaded->droppedBy, 'dropper is cleared when reopened');

        // Dropper is told.
        $this->assertCount(1, $this->em()->getRepository(Message::class)->findBy(['recipient' => $donor->id]));
    }

    // ---------------------------------------------------------------------
    // DELETE /api/bookcase/{id}/wishlist/{item}
    // ---------------------------------------------------------------------

    public function testDeleteRequiresAuth(): void
    {
        $item = WishlistItemFactory::new()->open()->create();
        $this->client->request('DELETE', '/api/bookcase/' . $item->bookcase->id . '/wishlist/' . $item->id);
        $this->assertResponseStatusCodeSame(401);
    }

    public function testDeleteByRequesterRemovesOpenWish(): void
    {
        $requester = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->open()->create(['bookcase' => $bc, 'user' => $requester]);
        $itemId = (string) $item->id;

        $this->client->request('DELETE', '/api/bookcase/' . $bc->id . '/wishlist/' . $itemId);
        $this->assertResponseIsSuccessful();
        $this->assertSame(0, $this->json()['openCount']);

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(WishlistItem::class)->find($itemId));
    }

    public function testDeleteByNonRequesterForbidden(): void
    {
        $requester = UserFactory::createOne();
        $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->open()->create(['bookcase' => $bc, 'user' => $requester]);

        $this->client->request('DELETE', '/api/bookcase/' . $bc->id . '/wishlist/' . $item->id);
        $this->assertResponseStatusCodeSame(403);
    }

    public function testDeleteNonOpenWishConflict(): void
    {
        $requester = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();
        $item = WishlistItemFactory::new()->dropped()->create(['bookcase' => $bc, 'user' => $requester]);

        $this->client->request('DELETE', '/api/bookcase/' . $bc->id . '/wishlist/' . $item->id);
        $this->assertResponseStatusCodeSame(409);
    }
}

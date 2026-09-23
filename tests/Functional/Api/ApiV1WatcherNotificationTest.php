<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Message;
use App\Enums\NotificationChannel;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;

/**
 * Changes made through /api/v1 notify a bookcase's watchers exactly like the
 * website does (edit, move, new wish) — but never the acting user themselves.
 */
final class ApiV1WatcherNotificationTest extends OAuthApiTestCase
{
    public function testPatchNotifiesWatchers(): void
    {
        [$bookcase, $token] = $this->watchedBookcase(['bookcases.write']);

        $this->api('PATCH', '/api/v1/bookcases/' . $bookcase->id, $token, ['title' => 'Renamed via API']);

        $this->assertSame(200, $this->statusCode());
        $this->assertSame(1, $this->messageCount());
    }

    public function testPositionNotifiesWatchers(): void
    {
        [$bookcase, $token] = $this->watchedBookcase(['bookcases.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bookcase->id . '/position', $token, ['latitude' => 48.1, 'longitude' => 11.6]);

        $this->assertSame(200, $this->statusCode());
        $this->assertSame(1, $this->messageCount());
    }

    public function testNewWishNotifiesWatchers(): void
    {
        [$bookcase, $token] = $this->watchedBookcase(['wishlist.write']);

        $this->api('POST', '/api/v1/bookcases/' . $bookcase->id . '/wishlist', $token, ['title' => 'Dune']);

        $this->assertSame(201, $this->statusCode());
        $this->assertSame(1, $this->messageCount());
    }

    public function testRejectedPatchNotifiesNobody(): void
    {
        [$bookcase, $token] = $this->watchedBookcase(['bookcases.write']);

        $this->api('PATCH', '/api/v1/bookcases/' . $bookcase->id, $token, ['title' => '']);

        $this->assertSame(422, $this->statusCode());
        $this->assertSame(0, $this->messageCount());
    }

    /** A bookcase with one (other) watcher, plus a token for the acting user. */
    private function watchedBookcase(array $scopes): array
    {
        $bookcase = BookcaseFactory::createOne(['title' => 'Before']);
        WatchlistItemFactory::createOne([
            'bookcase' => $bookcase,
            'user' => UserFactory::createOne(['notificationChannel' => NotificationChannel::Internal]),
        ]);
        $actor = UserFactory::createOne(['notificationChannel' => NotificationChannel::Internal]);
        // The actor watches too: they must not be notified about their own change.
        WatchlistItemFactory::createOne(['bookcase' => $bookcase, 'user' => $actor]);

        return [$bookcase, $this->tokenFor($actor, $scopes)];
    }

    private function messageCount(): int
    {
        return static::getContainer()->get('doctrine')->getManager()->getRepository(Message::class)->count([]);
    }
}

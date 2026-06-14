<?php declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\User;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Repository\WatchlistItemRepository;
use App\Repository\WishlistItemRepository;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Factory\WatchlistItemFactory;
use App\Tests\Factory\WishlistItemFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Covers the custom finder/mutator methods across the entity repositories that
 * the controller/web tests don't reach directly.
 */
final class RepositoryFindersTest extends KernelTestCase
{
    private function get(string $class): object
    {
        return self::getContainer()->get($class);
    }

    // ── UserRepository ───────────────────────────────────────────────────────

    public function testLoadUserByIdentifierAcceptsUsernameOrEmail(): void
    {
        $user = UserFactory::createOne(['username' => 'alice', 'email' => 'Alice@Example.com']);
        $repo = $this->get(UserRepository::class);

        $this->assertSame((string) $user->id, (string) $repo->loadUserByIdentifier('alice')->id);
        // E-mail match is case-insensitive.
        $this->assertSame((string) $user->id, (string) $repo->loadUserByIdentifier('alice@example.com')->id);
        $this->assertNull($repo->loadUserByIdentifier('nobody'));
    }

    public function testUpgradePasswordPersistsNewHashAndRejectsForeignUsers(): void
    {
        $user = UserFactory::createOne();
        $repo = $this->get(UserRepository::class);

        $repo->upgradePassword($user, 'new-hash');
        self::getContainer()->get('doctrine')->getManager()->clear();

        $reloaded = $repo->find($user->id);
        $this->assertSame('new-hash', $reloaded->password);

        $this->expectException(UnsupportedUserException::class);
        $repo->upgradePassword(new InMemoryUser('x', 'y'), 'irrelevant');
    }

    // ── MessageRepository ──────────────────────────────────────────────────────

    public function testMessageInboxCountUnreadAndMarkAllRead(): void
    {
        $user = UserFactory::createOne();
        $other = UserFactory::createOne();

        MessageFactory::createMany(2, ['recipient' => $user]);          // unread
        MessageFactory::new()->read()->create(['recipient' => $user]);  // read
        MessageFactory::createOne(['recipient' => $other]);             // someone else's

        $repo = $this->get(MessageRepository::class);

        $this->assertCount(3, $repo->findInboxFor($user), 'only this user\'s messages');
        $this->assertSame(2, $repo->countUnread($user));

        $repo->markAllReadFor($user);
        self::getContainer()->get('doctrine')->getManager()->clear();

        $this->assertSame(0, $repo->countUnread($user));
        $this->assertSame(1, $repo->countUnread($other), 'other user untouched');
    }

    public function testInboxIsOrderedOldestFirst(): void
    {
        $user = UserFactory::createOne();
        $first = MessageFactory::createOne(['recipient' => $user, 'subject' => 'first']);
        $second = MessageFactory::createOne(['recipient' => $user, 'subject' => 'second']);

        $inbox = $this->get(MessageRepository::class)->findInboxFor($user);
        $this->assertSame('first', $inbox[0]->subject);
        $this->assertSame('second', $inbox[1]->subject);
    }

    // ── WatchlistItemRepository ──────────────────────────────────────────────

    public function testWatchlistFinders(): void
    {
        $user = UserFactory::createOne();
        $bookcaseA = BookcaseFactory::createOne();
        $bookcaseB = BookcaseFactory::createOne();

        WatchlistItemFactory::createOne(['user' => $user, 'bookcase' => $bookcaseA]);
        WatchlistItemFactory::createOne(['user' => $user, 'bookcase' => $bookcaseB]);

        $repo = $this->get(WatchlistItemRepository::class);

        $this->assertNotNull($repo->findOneByUserAndBookcase($user, $bookcaseA));

        $watchedIds = $repo->findWatchedBookcaseIds($user);
        $this->assertCount(2, $watchedIds);
        $this->assertContains((string) $bookcaseA->id, $watchedIds);
        $this->assertContainsOnly('string', $watchedIds);

        $watchers = $repo->findWatcherUsersOf($bookcaseA);
        $this->assertCount(1, $watchers);
        $this->assertInstanceOf(User::class, $watchers[0]);
        $this->assertSame((string) $user->id, (string) $watchers[0]->id);
    }

    // ── WishlistItemRepository ───────────────────────────────────────────────

    public function testWishlistFindersAndOpenCount(): void
    {
        $user = UserFactory::createOne();
        $bookcase = BookcaseFactory::createOne();

        WishlistItemFactory::new()->open()->create(['bookcase' => $bookcase, 'user' => $user]);
        WishlistItemFactory::new()->open()->create(['bookcase' => $bookcase, 'user' => $user]);
        WishlistItemFactory::new()->dropped()->create(['bookcase' => $bookcase, 'user' => $user]);

        $repo = $this->get(WishlistItemRepository::class);

        $this->assertCount(3, $repo->findForBookcase($bookcase));
        $this->assertCount(3, $repo->findForUser($user));
        $this->assertSame(2, $repo->countOpen($bookcase), 'only OPEN wishes count');
    }
}

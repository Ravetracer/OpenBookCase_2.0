<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Repository\WatchlistItemRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A user's watch on a bookcase (change notifications). Idempotent add/remove,
 * shared by the website and the public API.
 */
class WatchlistService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly WatchlistItemRepository $watchlistItemRepository,
    ) {
    }

    public function isWatching(Bookcase $bookcase, ?User $user): bool
    {
        return $user !== null && $this->watchlistItemRepository->findOneByUserAndBookcase($user, $bookcase) !== null;
    }

    public function watch(Bookcase $bookcase, User $user): void
    {
        if ($this->watchlistItemRepository->findOneByUserAndBookcase($user, $bookcase) === null) {
            $item = new WatchlistItem();
            $item->user = $user;
            $item->bookcase = $bookcase;
            $this->entityManager->persist($item);
            $this->entityManager->flush();
        }
    }

    public function unwatch(Bookcase $bookcase, User $user): void
    {
        $item = $this->watchlistItemRepository->findOneByUserAndBookcase($user, $bookcase);
        if ($item !== null) {
            $this->entityManager->remove($item);
            $this->entityManager->flush();
        }
    }
}

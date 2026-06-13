<?php

namespace App\Repository;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Entity\WatchlistItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WatchlistItem>
 *
 * @method WatchlistItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method WatchlistItem|null findOneBy(array $criteria, array $orderBy = null)
 * @method WatchlistItem[]    findAll()
 * @method WatchlistItem[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WatchlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WatchlistItem::class);
    }

    public function save(WatchlistItem $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WatchlistItem $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByUserAndBookcase(User $user, Bookcase $bookcase): ?WatchlistItem
    {
        return $this->findOneBy(['user' => $user, 'bookcase' => $bookcase]);
    }

    /**
     * Bookcase ids (as strings) the given user is watching — seeds the map's
     * "only watched" filter without a per-marker join.
     *
     * @return string[]
     */
    public function findWatchedBookcaseIds(User $user): array
    {
        // Select the joined bookcase id as a field so Doctrine applies the ulid
        // type conversion (IDENTITY() would return raw binary). Each row's id is
        // hydrated to a Ulid; cast to the canonical string the markers use.
        $rows = $this->createQueryBuilder('w')
            ->select('b.id AS id')
            ->join('w.bookcase', 'b')
            ->andWhere('w.user = :user')
            ->setParameter('user', $user->id, 'ulid')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => (string) $r['id'], $rows);
    }

    /**
     * Every user watching the given bookcase (for change notifications).
     *
     * @return User[]
     */
    public function findWatcherUsersOf(Bookcase $bookcase): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('u')
            ->from(User::class, 'u')
            ->join('u.watchlistItems', 'w')
            ->andWhere('w.bookcase = :bookcase')
            ->setParameter('bookcase', $bookcase->id, 'ulid')
            ->getQuery()
            ->getResult();
    }
}

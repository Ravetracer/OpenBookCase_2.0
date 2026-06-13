<?php

namespace App\Repository;

use App\Entity\Bookcase;
use App\Entity\WishlistItem;
use App\Enums\WishlistItemStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WishlistItem>
 *
 * @method WishlistItem|null find($id, $lockMode = null, $lockVersion = null)
 * @method WishlistItem|null findOneBy(array $criteria, array $orderBy = null)
 * @method WishlistItem[]    findAll()
 * @method WishlistItem[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WishlistItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WishlistItem::class);
    }

    public function add(WishlistItem $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WishlistItem $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * All wishlist items of a bookcase with their requester and dropper eagerly
     * loaded (no N+1 in the modal). Ordered newest-first via the time-sortable ULID.
     *
     * @return WishlistItem[]
     */
    public function findForBookcase(Bookcase $bookcase): array
    {
        return $this->createQueryBuilder('wi')
            ->addSelect('requester', 'dropper')
            ->leftJoin('wi.user', 'requester')
            ->leftJoin('wi.droppedBy', 'dropper')
            ->where('wi.bookcase = :bookcase')
            ->setParameter('bookcase', $bookcase->id, 'ulid')
            ->orderBy('wi.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every wish the given user made, with its bookcase eagerly loaded (for the
     * profile overview). Ordered newest-first via the time-sortable ULID.
     *
     * @return WishlistItem[]
     */
    public function findForUser(\App\Entity\User $user): array
    {
        return $this->createQueryBuilder('wi')
            ->addSelect('bookcase')
            ->leftJoin('wi.bookcase', 'bookcase')
            ->where('wi.user = :user')
            ->setParameter('user', $user->id, 'ulid')
            ->orderBy('wi.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Number of still-open wishes on a bookcase (for the detail-dialog button).
     */
    public function countOpen(Bookcase $bookcase): int
    {
        return (int) $this->createQueryBuilder('wi')
            ->select('COUNT(wi.id)')
            ->where('wi.bookcase = :bookcase')
            ->andWhere('wi.status = :open')
            ->setParameter('bookcase', $bookcase->id, 'ulid')
            ->setParameter('open', WishlistItemStatus::Open->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

//    /**
//     * @return WishlistItem[] Returns an array of WishlistItem objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('w')
//            ->andWhere('w.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('w.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?WishlistItem
//    {
//        return $this->createQueryBuilder('w')
//            ->andWhere('w.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}

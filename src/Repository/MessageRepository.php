<?php

namespace App\Repository;

use App\Entity\Message;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Message>
 *
 * @method Message|null find($id, $lockMode = null, $lockVersion = null)
 * @method Message|null findOneBy(array $criteria, array $orderBy = null)
 * @method Message[]    findAll()
 * @method Message[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function save(Message $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Message $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function countUnread(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.recipient = :user')
            ->andWhere('m.readAt IS NULL')
            ->setParameter('user', $user->id, 'ulid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return Message[] Oldest first, so the inbox reads top-to-bottom like a chat.
     */
    public function findInboxFor(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.recipient = :user')
            ->setParameter('user', $user->id, 'ulid')
            ->orderBy('m.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Mark every unread message for the user as read. Bulk DQL keyed by id, so it
     * runs straight against the DB and leaves any already-loaded Message objects
     * untouched (they keep showing as "new" for the current render).
     */
    public function markAllReadFor(User $user): void
    {
        $this->getEntityManager()->createQuery(
            'UPDATE ' . Message::class . ' m SET m.readAt = :now WHERE m.recipient = :user AND m.readAt IS NULL'
        )
            ->setParameter('now', new \DateTimeImmutable())
            ->setParameter('user', $user->id, 'ulid')
            ->execute();
    }
}

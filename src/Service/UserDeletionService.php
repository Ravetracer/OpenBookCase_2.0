<?php

namespace App\Service;

use App\Entity\Image;
use App\Entity\Message;
use App\Entity\Rating;
use App\Entity\User;
use App\Entity\WatchlistItem;
use App\Entity\WishlistItem;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Removes a user account and all personal data, while keeping content that
 * should outlive the account (uploaded images, wishes the user dropped a copy
 * for). Shared by the user's own "delete my account" flow (ProfileController)
 * and the admin user-management area (AdminController) so both behave identically.
 *
 * API applications (Message threads included) cascade-delete at the DB level
 * via ApiApplication.applicant / Message.apiApplication onDelete rules; messages
 * sent *by* the user have sender SET NULL, decided applications have decidedBy
 * SET NULL — so the only relation needing manual cleanup is Message.recipient
 * (NOT NULL, no cascade).
 */
class UserDeletionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Delete the given user (by id) and their personal data. Returns true when a
     * user was found and removed, false when there was nothing to delete.
     */
    public function deleteUser(Ulid $userId): bool
    {
        // Bulk operations keyed by id (not the entity) so they run straight against
        // the DB regardless of what the UnitOfWork currently has managed.
        // Keep the uploaded images but sever the personal link.
        $this->entityManager->createQuery(
            'UPDATE ' . Image::class . ' i SET i.uploadedBy = NULL WHERE i.uploadedBy = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        // Delete all personal data tied to the account.
        $this->entityManager->createQuery(
            'DELETE ' . Rating::class . ' r WHERE r.user = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        $this->entityManager->createQuery(
            'DELETE ' . WishlistItem::class . ' w WHERE w.user = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        // Keep wishes this user dropped a copy for, but sever the personal link
        // (the wish stays valid; it just no longer points at a deleted account).
        $this->entityManager->createQuery(
            'UPDATE ' . WishlistItem::class . ' w SET w.droppedBy = NULL WHERE w.droppedBy = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        $this->entityManager->createQuery(
            'DELETE ' . Message::class . ' m WHERE m.recipient = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        $this->entityManager->createQuery(
            'DELETE ' . WatchlistItem::class . ' w WHERE w.user = :id'
        )->setParameter('id', $userId, 'ulid')->execute();

        // Detach any stale managed entities (e.g. images with the old owner snapshot)
        // so the flush below can't re-sync the link we just removed, then delete the user.
        $this->entityManager->clear();
        $user = $this->entityManager->getRepository(User::class)->find($userId);
        if ($user === null) {
            return false;
        }

        $this->entityManager->remove($user);
        $this->entityManager->flush();

        return true;
    }
}

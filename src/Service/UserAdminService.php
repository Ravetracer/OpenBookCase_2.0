<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Admin user management (AdminController): roles, suspension and the bulk
 * actions of the users list. Every operation refuses to let an admin lock
 * themselves out (self-suspend / self-demote / self-delete).
 *
 * Suspension also revokes the account's OAuth2 credentials — done by
 * App\Doctrine\UserSuspensionListener whenever the flag is flushed.
 */
class UserAdminService
{
    /**
     * Roles an admin may assign/revoke from the user-management UI. ROLE_USER is
     * implicit (granted to everyone in User::getRoles) and is never stored, so it
     * is not listed here. Add new roles to this list as they are introduced.
     *
     * @var list<string>
     */
    public const ASSIGNABLE_ROLES = ['ROLE_ADMIN'];

    public const BULK_RESEND = 'resend';
    public const BULK_SUSPEND = 'suspend';
    public const BULK_UNSUSPEND = 'unsuspend';
    public const BULK_DELETE = 'delete';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly UserDeletionService $userDeletion,
        private readonly EmailVerifier $emailVerifier,
    ) {
    }

    /** Is the given user the acting admin? (Self-action guard.) */
    public function isSelf(User $admin, User $user): bool
    {
        return $user->id !== null && (string) $admin->id === (string) $user->id;
    }

    /**
     * Resolve submitted ids to existing users, silently dropping anything that
     * is not a valid ULID or no longer exists.
     *
     * @param array<mixed> $ids
     *
     * @return list<User>
     */
    public function findUsers(array $ids): array
    {
        $found = [];
        foreach ($ids as $id) {
            if (\is_string($id) && Ulid::isValid($id)) {
                $user = $this->users->find(Ulid::fromString($id));
                if ($user instanceof User) {
                    $found[] = $user;
                }
            }
        }

        return $found;
    }

    /**
     * Replace the user's assignable roles. Returns false (nothing changed) when an
     * admin would remove their own ROLE_ADMIN and lock themselves out.
     *
     * @param array<mixed> $submitted
     */
    public function updateRoles(User $user, array $submitted, User $admin): bool
    {
        $roles = array_values(array_intersect(self::ASSIGNABLE_ROLES, $submitted));

        if ($this->isSelf($admin, $user) && !in_array('ROLE_ADMIN', $roles, true)) {
            return false;
        }

        $user->roles = $roles;
        $this->entityManager->flush();

        return true;
    }

    /** Suspend or reinstate an account. Returns false when an admin tries to suspend themselves. */
    public function setSuspended(User $user, bool $suspend, User $admin): bool
    {
        if ($suspend && $this->isSelf($admin, $user)) {
            return false;
        }

        $user->isSuspended = $suspend;
        $this->entityManager->flush();

        return true;
    }

    /**
     * Run one bulk action (a BULK_* constant) over the selected users. The acting
     * admin is skipped for suspend and delete. Returns the number of affected users
     * and whether the admin was skipped, or null for an unknown action.
     *
     * @param list<User> $users
     *
     * @return array{count: int, skippedSelf: bool}|null
     */
    public function bulk(string $action, array $users, User $admin, string $reason = ''): ?array
    {
        $count = 0;
        $skippedSelf = false;

        switch ($action) {
            case self::BULK_RESEND:
                foreach ($users as $user) {
                    $this->emailVerifier->sendVerification($user, $reason);
                    ++$count;
                }
                break;

            case self::BULK_SUSPEND:
            case self::BULK_UNSUSPEND:
                $suspend = $action === self::BULK_SUSPEND;
                foreach ($users as $user) {
                    if ($suspend && $this->isSelf($admin, $user)) {
                        $skippedSelf = true;
                        continue;
                    }
                    $user->isSuspended = $suspend;
                    ++$count;
                }
                $this->entityManager->flush();
                break;

            case self::BULK_DELETE:
                foreach ($users as $user) {
                    if ($this->isSelf($admin, $user)) {
                        $skippedSelf = true;
                        continue;
                    }
                    $this->userDeletion->deleteUser($user->id);
                    ++$count;
                }
                break;

            default:
                return null;
        }

        return ['count' => $count, 'skippedSelf' => $skippedSelf];
    }
}

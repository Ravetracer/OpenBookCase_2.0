<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiApplication;
use App\Entity\User;
use App\Enums\NotificationChannel;
use App\Repository\ApiApplicationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Repository\WishlistItemRepository;
use App\Security\EmailVerifier;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * A user's own account settings (ProfileController): profile modal data, e-mail
 * change with re-verification, notification channel and home map position.
 * The admin e-mail correction (AdminController) shares the e-mail checks.
 */
class UserAccountService
{
    public const EMAIL_CHANGED = 'changed';
    public const EMAIL_UNCHANGED = 'unchanged';
    public const EMAIL_INVALID = 'invalid';
    public const EMAIL_TAKEN = 'taken';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly UserRepository $users,
        private readonly WishlistItemRepository $wishlistItems,
        private readonly ApiApplicationRepository $apiApplications,
        private readonly MessageRepository $messages,
        private readonly EmailVerifier $emailVerifier,
    ) {
    }

    /**
     * Template variables for the profile modal body.
     *
     * @return array<string, mixed>
     */
    public function profileModalView(?User $user): array
    {
        $apiApplication = $user !== null ? $this->apiApplications->findLatestForUser($user) : null;

        return [
            'user' => $user,
            'wishlistItems' => $user !== null ? $this->wishlistItems->findForUser($user) : [],
            'apiApplication' => $apiApplication,
            'apiThread' => $apiApplication !== null ? $this->messages->findThreadForApplication($apiApplication) : [],
            'apiScopes' => ApiApplication::AVAILABLE_SCOPES,
        ];
    }

    /**
     * The user changes their own address. An unchanged address (case-insensitive)
     * is accepted idempotently; a changed one must be re-verified (login is
     * blocked by UserChecker until the new link is clicked), so a fresh
     * verification link is e-mailed to it.
     *
     * @return self::EMAIL_* outcome
     */
    public function changeOwnEmail(User $user, string $email): string
    {
        if (!$this->isValidEmail($email)) {
            return self::EMAIL_INVALID;
        }
        if (strcasecmp($email, (string) $user->email) === 0) {
            return self::EMAIL_UNCHANGED;
        }
        if ($this->isTakenByOther($user, $email)) {
            return self::EMAIL_TAKEN;
        }

        $this->storeUnverifiedEmail($user, $email);
        $this->emailVerifier->sendVerification($user);

        return self::EMAIL_CHANGED;
    }

    /**
     * An admin corrects a user's address. Always requires re-verification; no
     * mail is sent (the admin can trigger one separately).
     *
     * @return self::EMAIL_* outcome (never EMAIL_UNCHANGED)
     */
    public function changeEmailByAdmin(User $user, string $email): string
    {
        if (!$this->isValidEmail($email)) {
            return self::EMAIL_INVALID;
        }
        if ($this->isTakenByOther($user, $email)) {
            return self::EMAIL_TAKEN;
        }

        $this->storeUnverifiedEmail($user, $email);

        return self::EMAIL_CHANGED;
    }

    public function setNotificationChannel(User $user, NotificationChannel $channel): void
    {
        $user->notificationChannel = $channel;
        $this->entityManager->flush();
    }

    /**
     * Apply the profile's home-position form (`label`, `enabled`, `latitude`,
     * `longitude`, `zoom`). Coordinates are required whenever the feature is
     * enabled, otherwise the map would have nowhere to centre; disabling keeps
     * the stored values. Returns false (nothing saved) for out-of-range coordinates.
     */
    public function updateHome(User $user, InputBag $input): bool
    {
        // Optional user-chosen name (e.g. "Home", "Office"); empty → no label.
        $label = trim((string) $input->get('label'));
        $user->homeLabel = $label !== '' ? mb_substr($label, 0, 50) : null;

        $enabled = $input->getBoolean('enabled');

        if ($enabled || $input->has('latitude')) {
            $lat = (float) $input->get('latitude');
            $lon = (float) $input->get('longitude');
            $zoom = (int) $input->get('zoom');

            if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) {
                return false;
            }

            $user->homeLatitude = $lat;
            $user->homeLongitude = $lon;
            $user->homeZoom = max(1, min(19, $zoom ?: 13));
        }

        $user->useHomeLocation = $enabled;
        $this->entityManager->flush();

        return true;
    }

    /** Remove the home position entirely: clear the coordinates and disable it. */
    public function clearHome(User $user): void
    {
        $user->homeLatitude = null;
        $user->homeLongitude = null;
        $user->homeZoom = null;
        $user->homeLabel = null;
        $user->useHomeLocation = false;
        $this->entityManager->flush();
    }

    private function isValidEmail(string $email): bool
    {
        return count($this->validator->validate($email, [new Assert\NotBlank(), new Assert\Email()])) === 0;
    }

    /**
     * Is the address already registered to another account? A duplicate would
     * break login-by-email and misdirect password-reset links.
     */
    private function isTakenByOther(User $user, string $email): bool
    {
        $existing = $email !== '' ? $this->users->loadUserByIdentifier($email) : null;

        return $existing instanceof User && (string) $existing->id !== (string) $user->id;
    }

    /** A new address has not been proven yet — require re-verification. */
    private function storeUnverifiedEmail(User $user, string $email): void
    {
        $user->email = $email;
        $user->isVerified = false;
        $this->entityManager->flush();
    }
}

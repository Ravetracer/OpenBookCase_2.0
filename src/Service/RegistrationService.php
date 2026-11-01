<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Ulid;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

/**
 * Account sign-up and e-mail verification. A new account stays locked until the
 * e-mailed signed link is clicked; the user is never logged in automatically.
 */
class RegistrationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly UserRepository $users,
        private readonly EmailVerifier $emailVerifier,
        private readonly RegistrationBotGuard $botGuard,
    ) {
    }

    /**
     * Persist the new account with the hashed password and e-mail the verification
     * link. Returns false when the client is over the sign-up rate limit. A filled
     * honeypot returns true without creating anything, so the bot sees the same
     * success response as a human and learns nothing.
     */
    public function register(User $user, string $plainPassword, mixed $honeypot, ?string $clientIp): bool
    {
        if (!$this->botGuard->consumeSignUp($clientIp)) {
            return false;
        }
        if ($this->botGuard->isHoneypotFilled($honeypot, $user)) {
            return true;
        }

        $user->setPassword($this->hasher->hashPassword($user, $plainPassword));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->emailVerifier->sendVerification($user);

        return true;
    }

    /**
     * Validate a clicked verification link. The user is NOT logged in at that
     * point, so they are resolved from the signed URL's `id` parameter.
     * Returns false when the link names no known user.
     *
     * @throws VerifyEmailExceptionInterface when the signature is invalid or expired
     */
    public function verify(Request $request): bool
    {
        $id = $request->query->get('id');
        $user = ($id !== null && Ulid::isValid($id)) ? $this->users->find(Ulid::fromString($id)) : null;
        if ($user === null) {
            return false;
        }

        // Sets User::isVerified=true and persists.
        $this->emailVerifier->handleEmailConfirmation($request, $user);

        return true;
    }
}

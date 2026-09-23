<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Self-contained "forgot password" flow (no external bundle): a one-time, hashed,
 * one-hour token is stored on the user and e-mailed as a link. Used by the public
 * forgot-password form (ResetPasswordController) and the admin "send reset link"
 * action (AdminController).
 */
class PasswordResetService
{
    public const MIN_PASSWORD_LENGTH = 8;

    private const TOKEN_TTL = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $users,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly UserPasswordHasherInterface $hasher,
        private readonly LoggerInterface $logger,
        #[Target('password_reset')]
        private readonly RateLimiterFactoryInterface $resetLimiter,
    ) {
    }

    /**
     * Public forgot-password request. Silently does nothing when the client is over
     * the rate limit or the address is unknown, and never lets a mailer failure
     * escape — the caller always shows the same neutral screen, so neither the
     * throttle nor the outcome can be used as an address-enumeration oracle.
     */
    public function requestReset(string $email, ?string $clientIp): void
    {
        if (!$this->resetLimiter->create($clientIp ?? 'unknown')->consume(1)->isAccepted()) {
            return;
        }

        $email = trim($email);
        $user = $email !== '' ? $this->users->findOneBy(['email' => $email]) : null;
        if (!$user instanceof User) {
            return;
        }

        $message = $this->resetEmail($user, $this->issueToken($user));
        try {
            $this->mailer->send($message);
        } catch (\Throwable $e) {
            $this->logger->error('Password reset e-mail could not be sent.', ['exception' => $e]);
        }
    }

    /** Admin-triggered reset link; mailer failures propagate. */
    public function sendResetLink(User $user): void
    {
        $this->mailer->send($this->resetEmail($user, $this->issueToken($user)));
    }

    /** The user owning a still-valid (known and unexpired) reset token, else null. */
    public function findUserByToken(string $token): ?User
    {
        $user = $this->users->findOneBy(['resetTokenHash' => hash('sha256', $token)]);

        if (!$user instanceof User
            || $user->resetTokenExpiresAt === null
            || $user->resetTokenExpiresAt < new \DateTimeImmutable()) {
            return null;
        }

        return $user;
    }

    /** Translation key describing why the new password is rejected, or null when it is acceptable. */
    public function passwordError(string $password, string $repeat): ?string
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            return 'reset.password_too_short';
        }

        return $password !== $repeat ? 'reset.password_mismatch' : null;
    }

    public function resetPassword(User $user, string $password): void
    {
        $user->setPassword($this->hasher->hashPassword($user, $password));
        // Clear the one-time token so the link can't be reused.
        $user->resetTokenHash = null;
        $user->resetTokenExpiresAt = null;
        // Resetting via the e-mailed link proves ownership of the address.
        $user->isVerified = true;
        // A legacy account that resets now becomes a normal (migrated) account.
        $user->legacyUser = false;
        $user->legacyMigrated = true;
        $user->legacyPassword = '';

        $this->entityManager->flush();
    }

    /** Store a fresh hashed token on the user and return the raw one (for the link). */
    private function issueToken(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $user->resetTokenHash = hash('sha256', $token);
        $user->resetTokenExpiresAt = new \DateTimeImmutable(self::TOKEN_TTL);
        $this->entityManager->flush();

        return $token;
    }

    private function resetEmail(User $user, string $token): TemplatedEmail
    {
        $resetUrl = $this->urlGenerator->generate(
            'app_reset_password',
            ['token' => $token],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        return (new TemplatedEmail())
            ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
            ->to($user->email)
            ->subject($this->translator->trans('reset.email_subject'))
            ->htmlTemplate('security/reset_email.html.twig')
            ->context(['resetUrl' => $resetUrl, 'username' => $user->getUserIdentifier()]);
    }
}

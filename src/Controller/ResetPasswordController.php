<?php

namespace App\Controller;

use App\Entity\User;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Self-contained "forgot password" flow (no external bundle): a one-time, hashed,
 * one-hour token is stored on the user and e-mailed as a link. Clicking it lets
 * the user set a new password; the token is cleared on use.
 */
class ResetPasswordController extends AbstractController
{
    private const TOKEN_TTL = '+1 hour';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, MailerInterface $mailer): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_index');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token'))) {
                $this->addFlash('reset_error', $this->translator->trans('flash.invalid_token'));

                return $this->redirectToRoute('app_forgot_password');
            }

            $email = trim((string) $request->request->get('email'));
            $user = $email !== ''
                ? $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email])
                : null;

            // Only act when the account exists, but always show the same "check your
            // inbox" screen so the form can't be used to probe which e-mails exist.
            if ($user instanceof User) {
                $token = bin2hex(random_bytes(32));
                $user->resetTokenHash = hash('sha256', $token);
                $user->resetTokenExpiresAt = new \DateTimeImmutable(self::TOKEN_TTL);
                $this->entityManager->flush();

                $resetUrl = $this->generateUrl(
                    'app_reset_password',
                    ['token' => $token],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );

                $mailer->send((new TemplatedEmail())
                    ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
                    ->to($user->email)
                    ->subject($this->translator->trans('reset.email_subject'))
                    ->htmlTemplate('security/reset_email.html.twig')
                    ->context(['resetUrl' => $resetUrl, 'username' => $user->getUserIdentifier()]));
            }

            return $this->render('security/forgot_password.html.twig', ['sent' => true]);
        }

        return $this->render('security/forgot_password.html.twig', ['sent' => false]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token, UserPasswordHasherInterface $hasher): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_index');
        }

        $user = $this->entityManager->getRepository(User::class)
            ->findOneBy(['resetTokenHash' => hash('sha256', $token)]);

        // Reject unknown or expired tokens (don't reveal which).
        if (!$user instanceof User
            || $user->resetTokenExpiresAt === null
            || $user->resetTokenExpiresAt < new \DateTimeImmutable()) {
            $this->addFlash('reset_error', $this->translator->trans('reset.invalid_or_expired'));

            return $this->redirectToRoute('app_forgot_password');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_token'))) {
                $this->addFlash('reset_error', $this->translator->trans('flash.invalid_token'));

                return $this->redirectToRoute('app_reset_password', ['token' => $token]);
            }

            $password = (string) $request->request->get('password');
            $repeat = (string) $request->request->get('password_repeat');

            if (mb_strlen($password) < 6) {
                $this->addFlash('reset_error', $this->translator->trans('reset.password_too_short'));

                return $this->render('security/reset_password.html.twig', ['token' => $token]);
            }
            if ($password !== $repeat) {
                $this->addFlash('reset_error', $this->translator->trans('reset.password_mismatch'));

                return $this->render('security/reset_password.html.twig', ['token' => $token]);
            }

            $user->setPassword($hasher->hashPassword($user, $password));
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

            $this->addFlash('reset_success', $this->translator->trans('reset.success'));

            return $this->redirectToRoute('app_index');
        }

        return $this->render('security/reset_password.html.twig', ['token' => $token]);
    }
}

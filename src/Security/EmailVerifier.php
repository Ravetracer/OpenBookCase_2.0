<?php

namespace App\Security;

use App\Entity\User;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifier
{
    public function __construct(
        private VerifyEmailHelperInterface $verifyEmailHelper,
        private MailerInterface $mailer,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * E-mail the standard verification link to the user's current address,
     * optionally with an admin note (e.g. why a fresh link is being sent).
     */
    public function sendVerification(User $user, string $adminReason = ''): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
            ->to($user->email)
            ->subject($this->translator->trans('email.confirm_subject'))
            ->htmlTemplate('registration/confirmation_email.html.twig');

        if ($adminReason !== '') {
            $email->context(['adminReason' => $adminReason]);
        }

        $this->sendEmailConfirmation('app_verify_email', $user, $email);
    }

    public function sendEmailConfirmation(string $verifyEmailRouteName, UserInterface $user, TemplatedEmail $email): void
    {
        // The `id` MUST be included as an extra query parameter so that
        // RegistrationController::verifyUserEmail() can resolve the user from
        // the link (the user is not authenticated when clicking it). It also
        // becomes part of the signed URI. Omitting it makes every verification
        // link fail: the controller can't find the user and the account stays
        // locked.
        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            $verifyEmailRouteName,
            (string) $user->id,
            $user->email,
            ['id' => (string) $user->id]
        );

        $context = $email->getContext();
        $context['signedUrl'] = $signatureComponents->getSignedUrl();
        $context['expiresAtMessageKey'] = $signatureComponents->getExpirationMessageKey();
        $context['expiresAtMessageData'] = $signatureComponents->getExpirationMessageData();

        $email->context($context);

        $this->mailer->send($email);
    }

    /**
     * @throws VerifyEmailExceptionInterface
     */
    public function handleEmailConfirmation(Request $request, UserInterface $user): void
    {
        $this->verifyEmailHelper->validateEmailConfirmationFromRequest($request, $user->id, $user->email);

        $user->isVerified = true;

        $this->entityManager->persist($user);
        $this->entityManager->flush();
    }
}

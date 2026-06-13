<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Repository\UserRepository;
use App\Security\EmailVerifier;

use Doctrine\ORM\EntityManagerInterface;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Address;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    private EmailVerifier $emailVerifier;

    public function __construct(EmailVerifier $emailVerifier)
    {
        $this->emailVerifier = $emailVerifier;
    }

    #[Route('/register/modal', name: 'app_register_modal')]
    public function registerModal(): Response
    {
        $form = $this->createForm(RegistrationFormType::class, new User(), [
            'action' => $this->generateUrl('app_register'),
        ]);

        return $this->render('registration/register_modal.html.twig', [
            'registrationForm' => $form,
        ]);
    }

    #[Route('/register', name: 'app_register')]
    public function register(Request $request, UserPasswordHasherInterface $userPasswordHasher, EntityManagerInterface $entityManager, TranslatorInterface $translator): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // encode the plain password
            $user->setPassword(
                $userPasswordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $entityManager->persist($user);
            $entityManager->flush();

            // generate a signed url and email it to the user
            $this->emailVerifier->sendEmailConfirmation('app_verify_email', $user,
                (new TemplatedEmail())
                    ->from(new Address('info@openbookcase.de', 'OpenBookCase'))
                    ->to($user->email)
                    ->subject($translator->trans('email.confirm_subject'))
                    ->htmlTemplate('registration/confirmation_email.html.twig')
            );
            // Do NOT log the user in here — the account stays locked until the
            // user clicks the verification link e-mailed above. Show a
            // "check your inbox" confirmation instead.
            $this->addFlash('register_success', $translator->trans('flash.check_email'));

            return $this->redirectToRoute('app_index');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request, UserRepository $userRepository, TranslatorInterface $translator): Response
    {
        // The user is NOT logged in when clicking the link — resolve them from
        // the signed URL's `id` parameter instead of the security context.
        $id = $request->query->get('id');
        $user = ($id !== null && Ulid::isValid($id)) ? $userRepository->find(Ulid::fromString($id)) : null;

        if ($user === null) {
            $this->addFlash('register_error', $translator->trans('flash.verify_failed'));

            return $this->redirectToRoute('app_index');
        }

        // validate email confirmation link, sets User::isVerified=true and persists
        try {
            $this->emailVerifier->handleEmailConfirmation($request, $user);
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('register_error', $translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_index');
        }

        // Account unlocked. The user must now log in themselves.
        $this->addFlash('register_success', $translator->trans('flash.email_verified'));

        return $this->redirectToRoute('app_index');
    }
}

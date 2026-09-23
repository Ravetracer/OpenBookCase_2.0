<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\RegistrationFormType;
use App\Service\RegistrationService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;

class RegistrationController extends AbstractController
{
    public function __construct(
        private readonly RegistrationService $registration,
        private readonly TranslatorInterface $translator,
    ) {
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
    public function register(Request $request): Response
    {
        $user = new User();
        $form = $this->createForm(RegistrationFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->registration->register($user, $form->get('plainPassword')->getData());
            // Do NOT log the user in here — the account stays locked until the
            // user clicks the verification link. Show a "check your inbox"
            // confirmation instead.
            $this->addFlash('register_success', $this->translator->trans('flash.check_email'));

            return $this->redirectToRoute('app_index');
        }

        return $this->render('registration/register.html.twig', [
            'registrationForm' => $form->createView(),
        ]);
    }

    #[Route('/verify/email', name: 'app_verify_email')]
    public function verifyUserEmail(Request $request): Response
    {
        try {
            if (!$this->registration->verify($request)) {
                $this->addFlash('register_error', $this->translator->trans('flash.verify_failed'));

                return $this->redirectToRoute('app_index');
            }
        } catch (VerifyEmailExceptionInterface $exception) {
            $this->addFlash('register_error', $this->translator->trans($exception->getReason(), [], 'VerifyEmailBundle'));

            return $this->redirectToRoute('app_index');
        }

        // Account unlocked. The user must now log in themselves.
        $this->addFlash('register_success', $this->translator->trans('flash.email_verified'));

        return $this->redirectToRoute('app_index');
    }
}

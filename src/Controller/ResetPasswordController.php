<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\PasswordResetService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Public "forgot password" flow; the token handling lives in PasswordResetService.
 */
class ResetPasswordController extends AbstractController
{
    public function __construct(
        private readonly PasswordResetService $passwordReset,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/forgot-password', name: 'app_forgot_password', methods: ['GET', 'POST'])]
    public function request(Request $request, #[CurrentUser] ?User $user): Response
    {
        if ($user !== null) {
            return $this->redirectToRoute('app_index');
        }

        if (!$request->isMethod('POST')) {
            return $this->render('security/forgot_password.html.twig', ['sent' => false]);
        }

        if (!$this->isCsrfTokenValid('forgot_password', (string) $request->request->get('_token'))) {
            $this->addFlash('reset_error', $this->translator->trans('flash.invalid_token'));

            return $this->redirectToRoute('app_forgot_password');
        }

        // Always the same "check your inbox" screen, whatever happened.
        $this->passwordReset->requestReset((string) $request->request->get('email'), $request->getClientIp());

        return $this->render('security/forgot_password.html.twig', ['sent' => true]);
    }

    #[Route('/reset-password/{token}', name: 'app_reset_password', methods: ['GET', 'POST'])]
    public function reset(Request $request, string $token, #[CurrentUser] ?User $current): Response
    {
        if ($current !== null) {
            return $this->redirectToRoute('app_index');
        }

        // Reject unknown or expired tokens (don't reveal which).
        $user = $this->passwordReset->findUserByToken($token);
        if ($user === null) {
            $this->addFlash('reset_error', $this->translator->trans('reset.invalid_or_expired'));

            return $this->redirectToRoute('app_forgot_password');
        }

        if (!$request->isMethod('POST')) {
            return $this->render('security/reset_password.html.twig', ['token' => $token]);
        }

        if (!$this->isCsrfTokenValid('reset_password', (string) $request->request->get('_token'))) {
            $this->addFlash('reset_error', $this->translator->trans('flash.invalid_token'));

            return $this->redirectToRoute('app_reset_password', ['token' => $token]);
        }

        $password = (string) $request->request->get('password');
        $error = $this->passwordReset->passwordError($password, (string) $request->request->get('password_repeat'));
        if ($error !== null) {
            $this->addFlash('reset_error', $this->translator->trans($error));

            return $this->render('security/reset_password.html.twig', ['token' => $token]);
        }

        $this->passwordReset->resetPassword($user, $password);
        $this->addFlash('reset_success', $this->translator->trans('reset.success'));

        return $this->redirectToRoute('app_index');
    }
}

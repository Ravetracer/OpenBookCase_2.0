<?php

namespace App\Controller\Api;

use App\Entity\ApiApplication;
use App\Entity\User;
use App\Service\ApiApplicationService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The applicant's side of the API-access workflow, posted from the profile modal
 * (AJAX + CSRF, like ProfileController): submit a new application and reply within
 * a pending application's conversation thread. The service reports a rejected
 * request as an HttpException carrying the status and the translation key.
 */
#[Route('/profile/api', name: 'app_api_application_')]
#[IsGranted('ROLE_USER')]
class ApiApplicationController extends AbstractController
{
    public function __construct(
        private readonly ApiApplicationService $applicationService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/apply', name: 'apply', methods: ['POST'])]
    public function apply(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('api_apply', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->applicationService->submit($user, $request->request);
        } catch (HttpException $e) {
            return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], $e->getStatusCode());
        }

        return new JsonResponse(['status' => 'success'], Response::HTTP_CREATED);
    }

    #[Route('/{application}/reply', name: 'reply', methods: ['POST'])]
    public function reply(Request $request, ApiApplication $application, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('api_reply', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->applicationService->replyAsApplicant($application, $user, trim((string) $request->request->get('body')));
        } catch (HttpException $e) {
            return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], $e->getStatusCode());
        }

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    #[Route('/{application}/ack-secret', name: 'ack_secret', methods: ['POST'])]
    public function ackSecret(Request $request, ApiApplication $application, #[CurrentUser] User $user): JsonResponse
    {
        if (!$this->isCsrfTokenValid('api_secret_ack', (string) $request->request->get('_token'))) {
            return new JsonResponse(['error' => $this->translator->trans('flash.invalid_token')], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->applicationService->acknowledgeSecretAsApplicant($application, $user);
        } catch (HttpException $e) {
            return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], $e->getStatusCode());
        }

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }
}

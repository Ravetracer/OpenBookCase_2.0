<?php

namespace App\Controller\Api;

use App\Entity\ApiApplication;
use App\Entity\User;
use App\Enums\ApiClientType;
use App\Repository\ApiApplicationRepository;
use App\Service\ApiApplicationService;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The applicant's side of the API-access workflow, posted from the profile modal
 * (AJAX + CSRF, like ProfileController): submit a new application and reply within
 * a pending application's conversation thread.
 */
#[Route('/profile/api', name: 'app_api_application_')]
#[IsGranted('ROLE_USER')]
class ApiApplicationController extends AbstractController
{
    public function __construct(
        private readonly ApiApplicationService $applicationService,
        private readonly ApiApplicationRepository $applications,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/apply', name: 'apply', methods: ['POST'])]
    public function apply(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('api_apply', (string) $request->request->get('_token'))) {
            return $this->error('flash.invalid_token', Response::HTTP_BAD_REQUEST);
        }

        // Only one live application at a time; a denied/revoked one may be re-applied.
        $existing = $this->applications->findLatestForUser($user);
        if ($existing !== null && $existing->status->isOpen()) {
            return $this->error('flash.api_already_pending', Response::HTTP_CONFLICT);
        }
        if ($existing !== null && $existing->status === \App\Enums\ApiApplicationStatus::Approved) {
            return $this->error('flash.api_already_approved', Response::HTTP_CONFLICT);
        }

        $clientType = ApiClientType::tryFrom((string) $request->request->get('clientType'));
        if ($clientType === null) {
            return $this->error('flash.api_invalid_client_type', Response::HTTP_BAD_REQUEST);
        }

        $redirectUris = $this->parseLines((string) $request->request->get('redirectUris'));
        $scopes = array_values(array_intersect(
            $request->request->all('scopes'),
            ApiApplication::AVAILABLE_SCOPES,
        ));

        // Validate the entity's constraints (appName/useCase) before persisting.
        $draft = new ApiApplication();
        $draft->applicant = $user;
        $draft->appName = trim((string) $request->request->get('appName'));
        $draft->useCase = trim((string) $request->request->get('useCase'));
        $draft->clientType = $clientType;
        $draft->redirectUris = $redirectUris;
        $draft->requestedScopes = $scopes;

        if (count($this->validator->validate($draft)) > 0) {
            return $this->error('flash.api_invalid_application', Response::HTTP_BAD_REQUEST);
        }

        $this->applicationService->apply(
            $user,
            $draft->appName,
            $draft->useCase,
            $clientType,
            $redirectUris,
            $scopes,
        );

        return new JsonResponse(['status' => 'success'], Response::HTTP_CREATED);
    }

    #[Route('/{application}/reply', name: 'reply', methods: ['POST'])]
    public function reply(Request $request, ApiApplication $application): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('api_reply', (string) $request->request->get('_token'))) {
            return $this->error('flash.invalid_token', Response::HTTP_BAD_REQUEST);
        }

        // Only the applicant may reply, and only while the application is still open.
        if ($application->applicant?->id != $user->id) {
            return $this->error('flash.api_reply_forbidden', Response::HTTP_FORBIDDEN);
        }
        if (!$application->status->isOpen()) {
            return $this->error('flash.api_reply_closed', Response::HTTP_CONFLICT);
        }

        $body = trim((string) $request->request->get('body'));
        if ($body === '') {
            return $this->error('flash.api_reply_empty', Response::HTTP_BAD_REQUEST);
        }

        $this->applicationService->postMessage($application, $user, $body);

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    #[Route('/{application}/ack-secret', name: 'ack_secret', methods: ['POST'])]
    public function ackSecret(Request $request, ApiApplication $application): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('api_secret_ack', (string) $request->request->get('_token'))) {
            return $this->error('flash.invalid_token', Response::HTTP_BAD_REQUEST);
        }
        if ($application->applicant?->id != $user->id) {
            return $this->error('flash.api_reply_forbidden', Response::HTTP_FORBIDDEN);
        }

        $this->applicationService->acknowledgeSecret($application);

        return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
    }

    /** @return string[] non-empty, trimmed lines */
    private function parseLines(string $raw): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }

    private function error(string $key, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $this->translator->trans($key)], $status);
    }
}

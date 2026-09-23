<?php

namespace App\Service;

use App\Config\Locales;
use App\Entity\ApiApplication;
use App\Entity\Message;
use App\Entity\User;
use App\Enums\ApiApplicationStatus;
use App\Enums\ApiClientType;
use App\Enums\MessageType;
use App\Repository\ApiApplicationRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Orchestrates the API-access application lifecycle: a user applies, admins are
 * notified, the admin approves / denies / revokes, and the two sides exchange
 * scoped conversation messages ("check back"). All notifications go through
 * MessageService so each recipient's channel preference is honoured; bodies are
 * translated into each recipient's own language (same pattern as WishlistController).
 *
 * Phase 1 only handles the human workflow — OAuth client provisioning is wired
 * into approve()/revoke() in Phase 2.
 */
class ApiApplicationService
{
    /**
     * Redirect-URI schemes that can never be a legitimate OAuth callback and would
     * turn the authorization redirect into script execution or local-file access.
     * Everything else stays allowed: https, http (localhost development) and custom
     * app schemes such as `com.example.app:/callback` (PKCE mobile clients).
     */
    private const FORBIDDEN_REDIRECT_SCHEMES = ['javascript', 'data', 'vbscript', 'file'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageService $messageService,
        private readonly UserRepository $userRepository,
        private readonly MessageRepository $messageRepository,
        private readonly ApiApplicationRepository $applicationRepository,
        private readonly TranslatorInterface $translator,
        private readonly OAuthClientProvisioner $clientProvisioner,
        private readonly ValidatorInterface $validator,
    ) {
    }

    /**
     * Validate the applicant's form input and file the application. Only one live
     * application at a time; a denied/revoked one may be re-applied.
     *
     * @throws HttpException status + translation key of the error to show
     */
    public function submit(User $user, InputBag $input): ApiApplication
    {
        $existing = $this->applicationRepository->findLatestForUser($user);
        if ($existing !== null && $existing->status->isOpen()) {
            throw new HttpException(Response::HTTP_CONFLICT, 'flash.api_already_pending');
        }
        if ($existing !== null && $existing->status === ApiApplicationStatus::Approved) {
            throw new HttpException(Response::HTTP_CONFLICT, 'flash.api_already_approved');
        }

        $clientType = ApiClientType::tryFrom((string) $input->get('clientType'));
        if ($clientType === null) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'flash.api_invalid_client_type');
        }

        $redirectUris = array_values(array_filter(array_map(
            'trim',
            preg_split('/\r\n|\r|\n/', (string) $input->get('redirectUris')) ?: [],
        )));
        $scopes = array_values(array_intersect($input->all('scopes'), ApiApplication::AVAILABLE_SCOPES));

        // Validate the entity's constraints (appName/useCase) before persisting.
        $draft = new ApiApplication();
        $draft->applicant = $user;
        $draft->appName = trim((string) $input->get('appName'));
        $draft->useCase = trim((string) $input->get('useCase'));
        $draft->clientType = $clientType;
        $draft->redirectUris = $redirectUris;
        $draft->requestedScopes = $scopes;

        if (count($this->validator->validate($draft)) > 0 || !$this->redirectUrisAreSafe($redirectUris)) {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'flash.api_invalid_application');
        }

        return $this->apply($user, $draft->appName, $draft->useCase, $clientType, $redirectUris, $scopes);
    }

    /**
     * The applicant replies in their application's thread: only the applicant,
     * only while the application is still open, and never with an empty body.
     *
     * @throws HttpException status + translation key of the error to show
     */
    public function replyAsApplicant(ApiApplication $application, User $user, string $body): Message
    {
        $this->assertApplicant($application, $user);
        if (!$application->status->isOpen()) {
            throw new HttpException(Response::HTTP_CONFLICT, 'flash.api_reply_closed');
        }
        if ($body === '') {
            throw new HttpException(Response::HTTP_BAD_REQUEST, 'flash.api_reply_empty');
        }

        return $this->postMessage($application, $user, $body);
    }

    /**
     * @throws HttpException when the user is not the applicant
     */
    public function acknowledgeSecretAsApplicant(ApiApplication $application, User $user): void
    {
        $this->assertApplicant($application, $user);
        $this->acknowledgeSecret($application);
    }

    /**
     * @param string[] $redirectUris
     * @param string[] $scopes
     */
    public function apply(
        User $applicant,
        string $appName,
        string $useCase,
        ApiClientType $clientType,
        array $redirectUris,
        array $scopes,
    ): ApiApplication {
        $application = new ApiApplication();
        $application->applicant = $applicant;
        $application->appName = $appName;
        $application->useCase = $useCase;
        $application->clientType = $clientType;
        $application->redirectUris = array_values($redirectUris);
        $application->requestedScopes = array_values($scopes);
        $application->status = ApiApplicationStatus::Pending;

        $this->entityManager->persist($application);
        $this->entityManager->flush();

        // Inform every admin (informational, not part of the reply thread).
        foreach ($this->userRepository->findByRole('ROLE_ADMIN') as $admin) {
            $loc = $admin->language;
            $this->messageService->notify(
                $admin,
                $this->transFor($loc, 'notify.api_new_body', [
                    '%user%' => $applicant->getUserIdentifier(),
                    '%app%' => $appName,
                ]),
                MessageType::ApiAccess,
                $this->transFor($loc, 'notify.api_new_subject'),
            );
        }

        return $application;
    }

    public function approve(ApiApplication $application, User $admin): void
    {
        $this->decide($application, $admin, ApiApplicationStatus::Approved, null);
        // Provision the OAuth client; the one-time secret is stored on the application
        // (oauthPlainSecret) and revealed once in the applicant's profile.
        $this->clientProvisioner->provision($application);
        $loc = $application->applicant->language;
        $this->messageService->notify(
            $application->applicant,
            $this->transFor($loc, 'notify.api_approved_body', ['%app%' => $application->appName]),
            MessageType::ApiAccess,
            $this->transFor($loc, 'notify.api_approved_subject'),
            null,
            $admin,
            $application,
        );
    }

    public function deny(ApiApplication $application, User $admin, string $reason): void
    {
        $this->decide($application, $admin, ApiApplicationStatus::Denied, $reason);
        $loc = $application->applicant->language;
        $this->messageService->notify(
            $application->applicant,
            $this->transFor($loc, 'notify.api_denied_body', ['%app%' => $application->appName, '%reason%' => $reason]),
            MessageType::ApiAccess,
            $this->transFor($loc, 'notify.api_denied_subject'),
            null,
            $admin,
            $application,
        );
    }

    public function revoke(ApiApplication $application, User $admin, string $reason): void
    {
        $this->decide($application, $admin, ApiApplicationStatus::Revoked, $reason);
        // Disable the client + revoke all its tokens — access dies immediately.
        $this->clientProvisioner->revoke($application);
        $loc = $application->applicant->language;
        $this->messageService->notify(
            $application->applicant,
            $this->transFor($loc, 'notify.api_revoked_body', ['%app%' => $application->appName, '%reason%' => $reason]),
            MessageType::ApiAccess,
            $this->transFor($loc, 'notify.api_revoked_subject'),
            null,
            $admin,
            $application,
        );
    }

    /**
     * Post one conversation message in an application thread. Direction is derived
     * from the sender: applicant → routed to the relevant admin; admin → routed to
     * the applicant. The body is the author's free text (not templated).
     */
    public function postMessage(ApiApplication $application, User $sender, string $body): Message
    {
        $senderIsApplicant = $application->applicant?->id == $sender->id;
        $recipient = $senderIsApplicant
            ? $this->relevantAdminFor($application)
            : $application->applicant;

        $loc = $recipient->language;
        $subject = $this->transFor(
            $loc,
            $senderIsApplicant ? 'notify.api_reply_subject' : 'notify.api_question_subject',
            ['%app%' => $application->appName],
        );

        return $this->messageService->notify(
            $recipient,
            $body,
            MessageType::ApiAccess,
            $subject,
            null,
            $sender,
            $application,
        );
    }

    /** Forget the one-time client secret once the applicant has saved it (never re-surfaced). */
    public function acknowledgeSecret(ApiApplication $application): void
    {
        $application->oauthPlainSecret = null;
        $this->entityManager->flush();
    }

    /**
     * @param string[] $redirectUris
     */
    public function redirectUrisAreSafe(array $redirectUris): bool
    {
        foreach ($redirectUris as $uri) {
            // Browsers ignore control characters and whitespace inside a scheme
            // ("java\tscript:"), so strip them before comparing.
            $normalised = strtolower((string) preg_replace('/[\x00-\x20\x7f]+/', '', $uri));
            if (preg_match('/^([a-z][a-z0-9+.-]*):/', $normalised, $m) === 1
                && in_array($m[1], self::FORBIDDEN_REDIRECT_SCHEMES, true)) {
                return false;
            }
        }

        return true;
    }

    /** @throws HttpException 403 when the user did not file the application */
    private function assertApplicant(ApiApplication $application, User $user): void
    {
        if ($application->applicant?->id != $user->id) {
            throw new HttpException(Response::HTTP_FORBIDDEN, 'flash.api_reply_forbidden');
        }
    }

    private function decide(
        ApiApplication $application,
        User $admin,
        ApiApplicationStatus $status,
        ?string $reason,
    ): void {
        $application->status = $status;
        $application->decisionReason = $reason;
        $application->decidedBy = $admin;
        $application->decidedAt = new \DateTimeImmutable();
        $this->entityManager->flush();
    }

    /**
     * Who an applicant's reply should reach: the last admin who wrote in the
     * thread, else whoever decided it, else any admin. There is always at least
     * one admin (the workflow can't start otherwise).
     */
    private function relevantAdminFor(ApiApplication $application): User
    {
        foreach (array_reverse($this->messageRepository->findThreadForApplication($application)) as $message) {
            if ($message->sender !== null && $message->sender->id != $application->applicant?->id) {
                return $message->sender;
            }
        }

        if ($application->decidedBy !== null) {
            return $application->decidedBy;
        }

        return $this->userRepository->findByRole('ROLE_ADMIN')[0];
    }

    private function transFor(?string $locale, string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'messages', Locales::isSupported($locale) ? $locale : Locales::DEFAULT);
    }
}

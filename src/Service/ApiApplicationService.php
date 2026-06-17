<?php

namespace App\Service;

use App\Config\Locales;
use App\Entity\ApiApplication;
use App\Entity\Message;
use App\Entity\User;
use App\Enums\ApiApplicationStatus;
use App\Enums\ApiClientType;
use App\Enums\MessageType;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;

use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageService $messageService,
        private readonly UserRepository $userRepository,
        private readonly MessageRepository $messageRepository,
        private readonly TranslatorInterface $translator,
        private readonly OAuthClientProvisioner $clientProvisioner,
    ) {
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

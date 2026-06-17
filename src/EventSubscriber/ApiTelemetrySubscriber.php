<?php

namespace App\EventSubscriber;

use App\Entity\ApiUsageLog;
use App\Entity\User;
use App\Repository\ApiApplicationRepository;

use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Uid\Ulid;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Telemetry for authenticated /api/v1 requests: persists an {@see ApiUsageLog} row and
 * (in prod, best-effort) pings the Matomo HTTP Tracking API.
 *
 * Only requests carrying an OAuth token are recorded (open public-data reads are not).
 * The token/user are snapshotted on kernel.response (while the security token is live);
 * the DB write + Matomo call happen on kernel.terminate, after the response has been
 * sent to the client, so neither adds latency to the request the user sees.
 */
final class ApiTelemetrySubscriber implements EventSubscriberInterface
{
    private const MAX_PAYLOAD_BYTES = 8192;

    /** @var array<string, mixed>|null Snapshot captured on response, persisted on terminate. */
    private ?array $pending = null;

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiApplicationRepository $applications,
        private readonly HttpClientInterface $httpClient,
        private readonly string $matomoTrackingUrl,
        private readonly int $matomoSiteId,
        private readonly string $kernelEnvironment,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onResponse', -32],
            KernelEvents::TERMINATE => 'onTerminate',
        ];
    }

    public function onResponse(ResponseEvent $event): void
    {
        $this->pending = null;

        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return;
        }

        $token = $this->tokenStorage->getToken();
        if (!$token instanceof OAuth2Token) {
            return; // anonymous public-data read — not logged
        }

        $user = $token->getUser();

        $this->pending = [
            'clientId' => $token->getOAuthClientId(),
            'scopes' => $token->getScopes(),
            'userId' => $user instanceof User ? $user->id : null,
            'method' => $request->getMethod(),
            'routeName' => $request->attributes->get('_route'),
            'path' => mb_substr($request->getPathInfo(), 0, 255),
            'url' => $request->getUri(),
            'userAgent' => (string) $request->headers->get('User-Agent', ''),
            'payload' => $this->capturePayload($request),
            'status' => $event->getResponse()->getStatusCode(),
        ];
    }

    public function onTerminate(TerminateEvent $event): void
    {
        $data = $this->pending;
        $this->pending = null;
        if ($data === null) {
            return;
        }

        try {
            $this->persistLog($data);
        } catch (\Throwable) {
            // Telemetry must never break the API — swallow persistence failures.
        }

        if ($this->kernelEnvironment === 'prod' && $this->matomoTrackingUrl !== '') {
            $this->trackMatomo($data);
        }
    }

    /** @param array<string, mixed> $data */
    private function persistLog(array $data): void
    {
        $log = new ApiUsageLog();
        $log->oauthClientId = $data['clientId'];
        $log->apiApplication = $this->applications->findOneBy(['oauthClientId' => $data['clientId']]);
        $log->actingUser = $data['userId'] instanceof Ulid
            ? $this->entityManager->getReference(User::class, $data['userId'])
            : null;
        $log->method = $data['method'];
        $log->routeName = $data['routeName'];
        $log->path = $data['path'];
        $log->requestPayload = $data['payload'];
        $log->statusCode = $data['status'];

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }

    /**
     * Decode a small request payload for the audit log: the JSON body for writes, the
     * query params for reads. Multipart/binary bodies (image uploads) are omitted.
     *
     * @return array<string, mixed>|null
     */
    private function capturePayload(Request $request): ?array
    {
        if ($request->isMethodSafe()) {
            $query = $request->query->all();

            return $query === [] ? null : $query;
        }

        $contentType = (string) $request->headers->get('Content-Type', '');
        if (str_contains($contentType, 'multipart/form-data')) {
            return ['_note' => 'multipart/binary body omitted'];
        }

        $content = $request->getContent();
        if ($content === '') {
            return null;
        }
        if (strlen($content) > self::MAX_PAYLOAD_BYTES) {
            return ['_note' => 'payload too large to log (' . strlen($content) . ' bytes)'];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : ['_raw' => mb_substr($content, 0, 500)];
    }

    /** @param array<string, mixed> $data */
    private function trackMatomo(array $data): void
    {
        $action = $data['routeName'] ?: ($data['method'] . ' ' . $data['path']);
        $appLabel = $this->applications->findOneBy(['oauthClientId' => $data['clientId']])?->appName
            ?? $data['clientId'];

        try {
            // Fire-and-forget: we don't read the response; curl flushes it on shutdown.
            $this->httpClient->request('POST', $this->matomoTrackingUrl, [
                'timeout' => 2.0,
                'body' => [
                    'idsite' => (string) $this->matomoSiteId,
                    'rec' => '1',
                    'apiv' => '1',
                    'send_image' => '0',
                    'action_name' => 'API / ' . $action,
                    'url' => $data['url'],
                    'ua' => $data['userAgent'],
                    'dimension1' => (string) $appLabel,
                ],
            ]);
        } catch (\Throwable) {
            // Best-effort analytics — ignore any transport error.
        }
    }
}

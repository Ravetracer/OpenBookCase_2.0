<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate-limits the public API (^/api/v1) before the controller runs.
 *
 * Two sliding-window limiters: a loose one for reads (GET/HEAD), keyed by client IP
 * since open reads carry no token, and a tighter one for writes, keyed by the OAuth
 * access token (so each client/token gets its own budget). Over the limit → 429 with
 * a `Retry-After` header. Keying on the raw bearer token (not the resolved security
 * token) keeps this independent of the lazy firewall's authentication timing.
 */
final class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        #[Target('api_read')]
        private readonly RateLimiterFactoryInterface $apiReadLimiter,
        #[Target('api_write')]
        private readonly RateLimiterFactoryInterface $apiWriteLimiter,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After RouterListener (32), before the controller is resolved.
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1')) {
            return;
        }

        $isRead = $request->isMethodSafe();
        $token = $this->bearerToken($request);

        if ($isRead) {
            $key = 'ip:' . (string) $request->getClientIp();
            $limiter = $this->apiReadLimiter->create($key);
        } else {
            // Writes always carry a token (else the firewall 401s); fall back to IP just in case.
            $key = $token !== null ? 'tok:' . substr(hash('sha256', $token), 0, 32) : 'ip:' . (string) $request->getClientIp();
            $limiter = $this->apiWriteLimiter->create($key);
        }

        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new JsonResponse(
                ['error' => 'Rate limit exceeded. Please slow down and retry later.'],
                Response::HTTP_TOO_MANY_REQUESTS,
                [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $limit->getLimit(),
                    'X-RateLimit-Remaining' => '0',
                ],
            ));
        }
    }

    private function bearerToken(\Symfony\Component\HttpFoundation\Request $request): ?string
    {
        $header = $request->headers->get('Authorization', '');
        if (preg_match('/^Bearer\s+(\S+)/i', (string) $header, $m) === 1) {
            return $m[1];
        }

        return null;
    }
}

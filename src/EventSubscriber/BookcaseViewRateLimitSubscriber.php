<?php

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Twig\Environment;

/**
 * Rate-limits views of single bookcase entries (deep link, short link, detail
 * fragment and detail JSON) to slow down scrapers walking through every id.
 *
 * Keyed by the logged-in user's id, else by client IP — IPv6 by its /64 prefix,
 * since one client usually controls a whole /64. The `bookcase_view` compound
 * limiter (per minute / hour / day) is generous enough that browsing the map
 * by hand never reaches it. Over the limit → 429 with `Retry-After`: an HTML
 * page for the full-page routes, an empty body for the modal fragment (the
 * modal shows its own message) and JSON for the JSON endpoint.
 */
final class BookcaseViewRateLimitSubscriber implements EventSubscriberInterface
{
    private const PAGE_ROUTES = ['app_bookcase_show', 'app_short_link'];
    private const FRAGMENT_ROUTE = 'api_bookcase_retrieve_single_html';
    private const JSON_ROUTE = 'api_bookcase_retrieve_single';

    public function __construct(
        #[Target('bookcase_view')]
        private readonly RateLimiterFactoryInterface $bookcaseViewLimiter,
        private readonly Security $security,
        private readonly Environment $twig,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After the firewall (8) and LocaleSubscriber (6): needs the user and a
        // translated error page.
        return [KernelEvents::REQUEST => ['onRequest', 4]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = (string) $request->attributes->get('_route');
        $isPage = \in_array($route, self::PAGE_ROUTES, true);
        if (!$isPage && $route !== self::FRAGMENT_ROUTE && $route !== self::JSON_ROUTE) {
            return;
        }

        $limit = $this->bookcaseViewLimiter->create($this->key($request))->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $headers = [
            'Retry-After' => (string) $this->retryAfter($limit),
            'X-RateLimit-Limit' => (string) $limit->getLimit(),
            'X-RateLimit-Remaining' => '0',
        ];

        $event->setResponse(match (true) {
            $isPage => new Response($this->twig->render('error/too_many_requests.html.twig'), Response::HTTP_TOO_MANY_REQUESTS, $headers),
            $route === self::JSON_ROUTE => new JsonResponse(['error' => 'Too many requests. Please slow down and retry later.'], Response::HTTP_TOO_MANY_REQUESTS, $headers),
            default => new Response('', Response::HTTP_TOO_MANY_REQUESTS, $headers),
        });
    }

    private function key(Request $request): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User && $user->id !== null) {
            return 'user:' . $user->id->toBase32();
        }

        $ip = (string) $request->getClientIp();

        return 'ip:' . (str_contains($ip, ':') ? IpUtils::anonymize($ip, 0, 8) : $ip);
    }

    private function retryAfter(RateLimit $limit): int
    {
        return max(1, $limit->getRetryAfter()->getTimestamp() - time());
    }
}

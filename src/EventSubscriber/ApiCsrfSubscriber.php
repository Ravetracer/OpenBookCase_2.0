<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Same-origin (CSRF) guard for the internal, cookie-authenticated JSON API under
 * `^/api/bookcase` — ratings, watch/wishlist mutations, image upload/rotate/delete,
 * marker repositioning, and the soft-delete. These endpoints are called by the
 * app's own `fetch()` code and are authenticated by the session cookie, so a
 * cross-site page could otherwise forge them on behalf of a logged-in victim.
 *
 * It does NOT touch the public OAuth API (`^/api/v1`): that firewall is
 * Bearer-token authenticated and carries no ambient cookie, so it is immune to
 * CSRF by construction.
 *
 * The check mirrors the principle of Symfony's stateless `SameOriginCsrfTokenManager`:
 * an unsafe request is rejected only when it presents POSITIVE evidence of a
 * cross-origin source. Browsers attach `Sec-Fetch-*` metadata (a forbidden header
 * an attacker page cannot suppress) to every scripted cross-site write, and a
 * cross-origin `Origin` header to any cross-site POST/DELETE, so a real browser
 * CSRF attempt is always caught. Non-browser clients (curl, the test harness),
 * which send none of these signals and carry no session cookie, are unaffected —
 * they cannot mount a CSRF attack in the first place.
 *
 * This complements, and does not replace, the `SameSite=Lax` session cookie and
 * the stateless CSRF token already enforced on the Symfony Form endpoints
 * (`/api/bookcase/create`, `/save`).
 */
final class ApiCsrfSubscriber implements EventSubscriberInterface
{
    private const PROTECTED_PREFIX = '/api/bookcase';

    public static function getSubscribedEvents(): array
    {
        // Early in the request cycle, before the controller is resolved.
        return [KernelEvents::REQUEST => ['onRequest', 16]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Only state-changing methods need the guard (GET/HEAD/OPTIONS are safe).
        if ($request->isMethodSafe()) {
            return;
        }

        if (!str_starts_with($request->getPathInfo(), self::PROTECTED_PREFIX)) {
            return;
        }

        if ($this->isSameOrigin($request)) {
            return;
        }

        $event->setResponse(new JsonResponse(
            ['error' => 'Cross-origin request blocked.'],
            Response::HTTP_FORBIDDEN,
        ));
    }

    /**
     * True unless the request carries positive evidence of a cross-origin source.
     */
    private function isSameOrigin(Request $request): bool
    {
        // 1. Fetch metadata (all current browsers). 'same-origin' and 'none' (a
        //    direct user action) are safe; 'same-site' and 'cross-site' are not.
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null) {
            return $fetchSite === 'same-origin' || $fetchSite === 'none';
        }

        // 2. Fall back to an exact Origin/Referer scheme+host+port comparison.
        $target = $request->getSchemeAndHttpHost();
        foreach (['Origin', 'Referer'] as $header) {
            $value = (string) $request->headers->get($header, '');
            if ($value === '') {
                continue;
            }

            return $this->originMatches($value, $target);
        }

        // 3. No origin signal at all → not a browser-driven CSRF vector.
        return true;
    }

    private function originMatches(string $value, string $target): bool
    {
        $parts = parse_url($value);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $origin = $parts['scheme'] . '://' . $parts['host'] . $port;

        return strcasecmp($origin, $target) === 0;
    }
}

<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Adds baseline hardening headers to every main response:
 *
 *  - X-Frame-Options / CSP `frame-ancestors` → clickjacking protection.
 *  - X-Content-Type-Options: nosniff          → stop MIME-sniffing of /images uploads.
 *  - Referrer-Policy                          → don't leak full URLs cross-origin.
 *  - Permissions-Policy                       → geolocation stays first-party; camera/mic off.
 *  - Content-Security-Policy                  → defence-in-depth around Twig's escaping.
 *  - Strict-Transport-Security (HTTPS only)   → prevent protocol downgrade.
 *
 * The CSP is scoped to the real third parties the app talks to: OpenStreetMap
 * tiles (img), the Photon geocoder (connect), and the self-hosted Matomo tracker
 * (script/connect). Inline `<script>`/`style` blocks in base.html.twig and
 * Leaflet's inline element styles require `'unsafe-inline'`; `frame-ancestors`,
 * `object-src` and `base-uri` carry the real hardening value.
 */
final class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    private const CSP =
        "default-src 'self'; "
        . "base-uri 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "form-action 'self'; "
        . "img-src 'self' data: https:; "
        . "script-src 'self' 'unsafe-inline' https://matomo.openbookcase.de; "
        . "style-src 'self' 'unsafe-inline'; "
        . "font-src 'self'; "
        . "connect-src 'self' https://photon.komoot.io https://matomo.openbookcase.de; "
        . "worker-src 'self'; "
        . "manifest-src 'self'";

    public static function getSubscribedEvents(): array
    {
        // Late, so nothing downstream strips the headers; runs on every response.
        return [KernelEvents::RESPONSE => ['onResponse', -128]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;

        // Don't advertise the exact PHP version / server software. `header_remove`
        // drops the SAPI-added `X-Powered-By` (when php.ini `expose_php` is On and
        // cannot be changed); the bag removal covers any framework-set copy.
        header_remove('X-Powered-By');
        $headers->remove('X-Powered-By');

        // `false` = don't overwrite a header a controller deliberately set.
        $headers->set('X-Frame-Options', 'DENY', false);
        $headers->set('X-Content-Type-Options', 'nosniff', false);
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(), payment=()', false);
        $headers->set('Content-Security-Policy', self::CSP, false);

        // HSTS only over HTTPS — it is meaningless (and undesirable) on plain-HTTP
        // local development.
        if ($event->getRequest()->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains', false);
        }
    }
}

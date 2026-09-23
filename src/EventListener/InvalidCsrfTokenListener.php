<?php declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;

/**
 * A failed `#[IsCsrfTokenValid]` check throws InvalidCsrfTokenException, which the
 * firewall would treat as "not authenticated" and bounce a logged-in user to the
 * login page. Instead: flash the usual "invalid token" error and go back to the
 * page the form was submitted from. Nothing has been changed at this point.
 * Runs before the firewall's exception listener (priority 1).
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 2)]
final class InvalidCsrfTokenListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->getThrowable() instanceof InvalidCsrfTokenException || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', 'flash.invalid_token');
        }

        // Only a same-host Referer is followed (no open redirect).
        $referer = (string) $request->headers->get('referer', '');
        $sameHost = $referer !== '' && parse_url($referer, PHP_URL_HOST) === $request->getHost();
        $event->setResponse(new RedirectResponse($sameHost ? $referer : '/'));
    }
}

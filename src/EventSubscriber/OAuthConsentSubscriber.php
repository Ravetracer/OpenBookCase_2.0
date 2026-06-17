<?php

namespace App\EventSubscriber;

use League\Bundle\OAuth2ServerBundle\Event\AuthorizationRequestResolveEvent;
use League\Bundle\OAuth2ServerBundle\OAuth2Events;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * Drives the OAuth2 authorization-code consent screen.
 *
 * On the first GET of /oauth/authorize (user already logged in — enforced by the
 * access_control rule) we render a consent page listing the app + requested scopes.
 * The page POSTs back to the same URL (query string preserved) with a consent_action;
 * we then resolve the authorization (approve → code is issued, deny → access_denied).
 * CSRF-protected; an invalid/absent token is treated as a denial.
 */
final class OAuthConsentSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Environment $twig,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [OAuth2Events::AUTHORIZATION_REQUEST_RESOLVE => 'onAuthorizationRequest'];
    }

    public function onAuthorizationRequest(AuthorizationRequestResolveEvent $event): void
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return;
        }

        if ($request->isMethod('POST') && $request->request->has('consent_action')) {
            $tokenValid = $this->csrfTokenManager->isTokenValid(
                new CsrfToken('oauth_consent', (string) $request->request->get('_token')),
            );
            $approved = $tokenValid && $request->request->get('consent_action') === 'approve';
            $event->resolveAuthorization($approved);

            return;
        }

        $event->setResponse(new Response($this->twig->render('oauth/consent.html.twig', [
            'client' => $event->getClient(),
            'scopes' => $event->getScopes(),
            'consentToken' => $this->csrfTokenManager->getToken('oauth_consent')->getValue(),
        ])));
    }
}

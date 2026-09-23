<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\EventSubscriber\LocaleSubscriber;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Persists a UI-language choice: on the profile (User.language) for logged-in
 * users, and in the `obc_locale` cookie for everyone (anonymous persistence).
 */
class LocaleService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Save an (already validated) locale on the user, if any, and return the
     * cookie the caller must attach to its response.
     */
    public function remember(?User $user, string $locale): Cookie
    {
        if ($user !== null) {
            $user->language = $locale;
            $this->entityManager->flush();
        }

        return Cookie::create(LocaleSubscriber::COOKIE, $locale, strtotime('+1 year'), '/', null, false, false);
    }

    /**
     * Redirect back to the page the user came from, but only if it's on this
     * host (avoid an open redirect); otherwise the homepage.
     *
     * The host is compared exactly after parsing — a prefix check (`str_starts_with`)
     * is unsafe because an attacker-controlled host such as `example.com.evil.tld`
     * begins with the site origin as a string and would sail through.
     */
    public function safeRedirectTarget(Request $request): string
    {
        $referer = (string) $request->headers->get('referer', '');
        if ($referer !== '') {
            $parts = parse_url($referer);
            if (
                isset($parts['host'])
                && strcasecmp($parts['host'], $request->getHost()) === 0
                && (!isset($parts['scheme']) || strcasecmp($parts['scheme'], $request->getScheme()) === 0)
            ) {
                return $referer;
            }
        }

        return $this->urlGenerator->generate('app_index');
    }
}

<?php declare(strict_types=1);

namespace App\EventSubscriber;

use App\Config\Locales;
use App\Entity\User;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

/**
 * Resolves the active locale on every (main) request. Runs at priority 6 — after
 * the firewall (priority 8) so the authenticated user is available, but still
 * during kernel.request, before the controller/template render.
 *
 * Order of preference: the logged-in user's saved language → the `obc_locale`
 * cookie (anonymous persistence) → the browser's Accept-Language → default.
 *
 * It sets the locale on BOTH the request and the translator: a late
 * $request->setLocale() alone may not reach the translator (Symfony docs), so we
 * push it explicitly to avoid any listener-ordering fragility.
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public const COOKIE = 'obc_locale';

    public function __construct(
        private readonly Security $security,
        private readonly LocaleAwareInterface $translator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $locale = $this->resolve($request->cookies->get(self::COOKIE), $request->getPreferredLanguage(Locales::codes()));

        $request->setLocale($locale);
        $this->translator->setLocale($locale);
    }

    private function resolve(?string $cookie, ?string $preferred): string
    {
        $user = $this->security->getUser();
        if ($user instanceof User && Locales::isSupported($user->language)) {
            return $user->language;
        }

        if (Locales::isSupported($cookie)) {
            return $cookie;
        }

        return Locales::isSupported($preferred) ? $preferred : Locales::DEFAULT;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => [['onKernelRequest', 6]],
        ];
    }
}

<?php declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Suspension must take effect immediately, not when the session expires: the
 * UserChecker only runs at login, so a session user whose (refreshed-from-DB)
 * account is now suspended is logged out on their next request.
 * Runs right after the firewall (priority 8).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 7)]
final class SuspendedUserLogoutListener
{
    public function __construct(private readonly Security $security)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || $this->security->getFirewallConfig($event->getRequest())?->getName() !== 'main') {
            return;
        }

        $user = $this->security->getUser();
        if ($user instanceof User && $user->isSuspended) {
            // Drop the session login and let the request continue anonymously, so
            // the requested action fails as unauthenticated (401 / login redirect).
            $this->security->logout(false);
        }
    }
}

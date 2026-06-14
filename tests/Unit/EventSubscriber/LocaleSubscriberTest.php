<?php declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\LocaleAwareInterface;

final class LocaleSubscriberTest extends TestCase
{
    private function dispatch(?User $user, ?string $cookie, ?string $acceptLanguage, int $requestType = HttpKernelInterface::MAIN_REQUEST): Request
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        $translator = $this->createMock(LocaleAwareInterface::class);

        $subscriber = new LocaleSubscriber($security, $translator);

        $request = new Request();
        if ($cookie !== null) {
            $request->cookies->set(LocaleSubscriber::COOKIE, $cookie);
        }
        if ($acceptLanguage !== null) {
            $request->headers->set('Accept-Language', $acceptLanguage);
        }

        $event = new RequestEvent($this->createMock(HttpKernelInterface::class), $request, $requestType);
        $subscriber->onKernelRequest($event);

        return $request;
    }

    public function testListensOnKernelRequest(): void
    {
        $this->assertArrayHasKey(KernelEvents::REQUEST, LocaleSubscriber::getSubscribedEvents());
    }

    public function testLoggedInUserLanguageWinsOverCookie(): void
    {
        $user = new User();
        $user->language = 'ru';

        $request = $this->dispatch($user, 'de', 'fr-FR');
        $this->assertSame('ru', $request->getLocale());
    }

    public function testCookieUsedForAnonymousVisitor(): void
    {
        $request = $this->dispatch(null, 'de', 'fr-FR');
        $this->assertSame('de', $request->getLocale());
    }

    public function testFallsBackToPreferredBrowserLanguage(): void
    {
        $request = $this->dispatch(null, null, 'fr-FR,fr;q=0.9');
        $this->assertSame('fr', $request->getLocale());
    }

    public function testUnsupportedEverythingFallsBackToDefault(): void
    {
        $request = $this->dispatch(null, 'it', 'it-IT');
        $this->assertSame('en', $request->getLocale());
    }

    public function testSubRequestIsIgnored(): void
    {
        $request = $this->dispatch(null, 'de', null, HttpKernelInterface::SUB_REQUEST);
        // Default Request locale is "en"; the subscriber must not have touched it.
        $this->assertSame('en', $request->getLocale());
    }
}

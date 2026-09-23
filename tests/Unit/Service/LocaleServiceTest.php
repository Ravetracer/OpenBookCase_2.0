<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\EventSubscriber\LocaleSubscriber;
use App\Service\LocaleService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class LocaleServiceTest extends TestCase
{
    /** @return iterable<string, array{string, string}> referer → expected redirect target */
    public static function referers(): iterable
    {
        yield 'same host' => ['http://localhost/list?q=x', 'http://localhost/list?q=x'];
        yield 'same host, other case' => ['http://LOCALHOST/help', 'http://LOCALHOST/help'];
        yield 'no referer' => ['', '/'];
        yield 'foreign host' => ['https://evil.example/', '/'];
        yield 'host suffix trick' => ['http://localhost.evil.example/', '/'];
        yield 'userinfo trick' => ['http://localhost@evil.example/', '/'];
        yield 'scheme mismatch' => ['javascript://localhost/%0aalert(1)', '/'];
        yield 'relative path' => ['/list', '/'];
    }

    #[DataProvider('referers')]
    public function testSafeRedirectTarget(string $referer, string $expected): void
    {
        $request = Request::create('http://localhost/language/de');
        if ($referer !== '') {
            $request->headers->set('referer', $referer);
        }

        $this->assertSame($expected, $this->service()->safeRedirectTarget($request));
    }

    public function testRememberStoresLocaleOnUserAndReturnsCookie(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');
        $user = new User();

        $cookie = $this->service($em)->remember($user, 'fr');

        $this->assertSame('fr', $user->language);
        $this->assertSame(LocaleSubscriber::COOKIE, $cookie->getName());
        $this->assertSame('fr', $cookie->getValue());
    }

    public function testRememberForAnonymousOnlyReturnsCookie(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('flush');

        $this->assertSame('nl', $this->service($em)->remember(null, 'nl')->getValue());
    }

    private function service(?EntityManagerInterface $em = null): LocaleService
    {
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/');

        return new LocaleService($em ?? $this->createStub(EntityManagerInterface::class), $urls);
    }
}

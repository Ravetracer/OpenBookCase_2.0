<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\EventSubscriber\LocaleSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;

final class LocaleControllerTest extends FunctionalTestCase
{
    #[DataProvider('supportedLocaleProvider')]
    public function testSwitchSetsLocaleCookie(string $locale): void
    {
        $this->client->request('GET', '/language/'.$locale);

        // Redirects back to the homepage (no referer header).
        $this->assertResponseRedirects('/');

        $cookie = $this->client->getResponse()->headers->getCookies();
        $found = null;
        foreach ($cookie as $c) {
            if ($c->getName() === LocaleSubscriber::COOKIE) {
                $found = $c;
                break;
            }
        }

        $this->assertNotNull($found, 'expected the obc_locale cookie to be set');
        $this->assertSame($locale, $found->getValue());
    }

    /** @return array<string, array{string}> */
    public static function supportedLocaleProvider(): array
    {
        return [
            'english' => ['en'],
            'german' => ['de'],
            'french' => ['fr'],
        ];
    }

    public function testUnsupportedLocaleReturns404(): void
    {
        $this->client->request('GET', '/language/zz');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testSwitchHonoursSameHostReferer(): void
    {
        $this->client->request('GET', '/language/de', [], [], [
            'HTTP_REFERER' => 'http://localhost/list',
        ]);

        $this->assertResponseRedirects('http://localhost/list');
    }
}

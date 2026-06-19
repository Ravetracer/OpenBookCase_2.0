<?php declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;

final class StaticPageControllerTest extends FunctionalTestCase
{
    #[DataProvider('staticPageProvider')]
    public function testStaticPageReturns200(string $path): void
    {
        $this->client->request('GET', $path);
        $this->assertResponseIsSuccessful();
    }

    /** @return array<string, array{string}> */
    public static function staticPageProvider(): array
    {
        return [
            'help' => ['/help'],
            'about' => ['/about'],
            'imprint' => ['/imprint'],
            'legal' => ['/legal'],
            'licenses' => ['/licenses'],
            'changelog' => ['/changelog'],
            'developers' => ['/developers'],
        ];
    }

    /**
     * The /help, /changelog and /developers pages have a written per-locale partial
     * for every supported UI locale (no English fallback for real locales). A missing
     * partial would make the Twig include throw → HTTP 500, so a plain 200 per locale
     * guards that all six partials exist and are wired.
     */
    #[DataProvider('localizedStaticPageProvider')]
    public function testLocalizedStaticPageRendersInEveryLocale(string $path, string $locale): void
    {
        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie('obc_locale', $locale),
        );
        $this->client->request('GET', $path);
        $this->assertResponseIsSuccessful();
    }

    /** @return iterable<string, array{string, string}> */
    public static function localizedStaticPageProvider(): iterable
    {
        foreach (['/help', '/changelog', '/developers'] as $path) {
            foreach (['en', 'de', 'ru', 'nl', 'es', 'fr'] as $locale) {
                yield "$path [$locale]" => [$path, $locale];
            }
        }
    }

    public function testDevelopersPageShowsApiDocs(): void
    {
        $html = $this->client->request('GET', '/developers')->html();
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('/api/v1/bookcases', $html);
        $this->assertStringContainsString('/oauth/token', $html);
        $this->assertStringContainsString('code_challenge', $html); // PKCE example present
    }

    public function testImprintRespectsGermanLocaleCookie(): void
    {
        // German is the authoritative legal text; the English variant carries a
        // "convenience translation" note. Assert the locale cookie selects German.
        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie('obc_locale', 'de'),
        );
        $de = $this->client->request('GET', '/imprint')->html();
        $this->assertResponseIsSuccessful();

        $this->client->getCookieJar()->set(
            new \Symfony\Component\BrowserKit\Cookie('obc_locale', 'en'),
        );
        $en = $this->client->request('GET', '/imprint')->html();
        $this->assertResponseIsSuccessful();

        // The two locales should not render identical body text.
        $this->assertNotSame($de, $en, 'imprint should differ between de and en locales');
    }
}

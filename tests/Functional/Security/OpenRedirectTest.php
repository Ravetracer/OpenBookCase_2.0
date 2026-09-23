<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Open redirects: no endpoint that answers with a redirect may be steered to a
 * foreign host through a request parameter (target/failure/next/returnUrl…) or
 * the Referer header. (The OAuth redirect_uri variant lives in OAuthAbuseTest;
 * the basic language-switch Referer cases in LocaleControllerTest.)
 */
final class OpenRedirectTest extends FunctionalTestCase
{
    private const EVIL = 'https://evil.example/pwned';

    private const REDIRECT_PARAMS = ['_target_path', 'target_path', '_failure_path', 'redirect', 'redirect_uri',
        'next', 'returnUrl', 'return_to', 'target', 'url'];

    /** @return iterable<string, array{string, string}> */
    public static function redirectingEndpoints(): iterable
    {
        yield 'login GET' => ['GET', '/login'];
        yield 'login POST (failed)' => ['POST', '/login'];
        yield 'logout' => ['GET', '/logout'];
        yield 'verify email' => ['GET', '/verify/email'];
        yield 'reset password (bad token)' => ['GET', '/reset-password/not-a-token'];
        yield 'language switch' => ['GET', '/language/de'];
        yield 'short link' => ['GET', '/s/abc123'];
    }

    #[DataProvider('redirectingEndpoints')]
    public function testRedirectParametersCannotPointOffSite(string $method, string $path): void
    {
        $params = array_fill_keys(self::REDIRECT_PARAMS, self::EVIL) + ['username' => 'nobody', 'password' => 'x', '_csrf_token' => 'csrf-token'];

        $this->client->request($method, $path, $params, [], ['HTTP_REFERER' => 'http://localhost/']);

        $this->assertNotOffSite();
    }

    public function testSuccessfulLoginIgnoresATargetPathParameter(): void
    {
        UserFactory::createOne(['username' => 'redirectee']);

        $this->client->request('POST', '/login', [
            'username' => 'redirectee',
            'password' => UserFactory::PLAIN_PASSWORD,
            '_csrf_token' => 'csrf-token',
        ] + array_fill_keys(self::REDIRECT_PARAMS, self::EVIL), [], ['HTTP_REFERER' => 'http://localhost/']);

        $this->assertNotNull(static::getContainer()->get('security.token_storage')->getToken(), 'login itself must succeed');
        $this->assertResponseRedirects('/');
    }

    public function testLoggedInLogoutIgnoresTargetParameters(): void
    {
        $this->loginAsUser();

        $this->client->request('GET', '/logout', array_fill_keys(self::REDIRECT_PARAMS, self::EVIL));

        $this->assertNotOffSite();
    }

    /** @return iterable<string, array{string}> */
    public static function trickyReferers(): iterable
    {
        yield 'protocol-relative' => ['//evil.example/pwned'];
        yield 'https vs http' => ['https://localhost/'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,<script>alert(1)</script>'];
        yield 'uppercase host' => ['http://EVIL.example/'];
    }

    #[DataProvider('trickyReferers')]
    public function testLanguageSwitchRejectsTrickyReferers(string $referer): void
    {
        $this->client->request('GET', '/language/de', [], [], ['HTTP_REFERER' => $referer]);

        $this->assertResponseRedirects('/');
    }

    private function assertNotOffSite(): void
    {
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $host = parse_url($location, PHP_URL_HOST);

        $this->assertStringNotContainsStringIgnoringCase('evil.example', $location, "redirected off-site: $location");
        $this->assertTrue($host === null || $host === false || $host === 'localhost', "redirected off-site: $location");
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enums\ApiClientType;
use App\Service\OAuthClientProvisioner;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;

/**
 * Reproduces what an Android app (public client, Authorization Code + PKCE) does:
 * open /oauth/authorize in a browser tab, get bounced to login, log in via the
 * form, come back, consent, get a code, exchange it with the code_verifier.
 */
final class OAuthAndroidFlowTest extends FunctionalTestCase
{
    private const REDIRECT = 'myapp://callback';

    public function testUnauthenticatedAuthorizeShowsLoginForm(): void
    {
        $app = ApiApplicationFactory::createOne([
            'clientType' => ApiClientType::PublicClient,
            'redirectUris' => [self::REDIRECT],
            'requestedScopes' => ['bookcases.write'],
        ]);
        static::getContainer()->get(OAuthClientProvisioner::class)->provision($app);

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $this->client->followRedirects(true);
        $crawler = $this->client->request('GET', '/oauth/authorize', [
            'response_type' => 'code',
            'client_id' => $app->oauthClientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'bookcases.write',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);

        $this->assertResponseIsSuccessful();
        // The page the user lands on must actually present a login form.
        $this->assertGreaterThan(
            0,
            $crawler->filter('input[name="username"]')->count(),
            'Expected a visible login form after being redirected for authentication.',
        );
    }

    public function testPublicClientPkceFullFlow(): void
    {
        $app = ApiApplicationFactory::createOne([
            'clientType' => ApiClientType::PublicClient,
            'redirectUris' => [self::REDIRECT],
            'requestedScopes' => ['bookcases.write'],
        ]);
        static::getContainer()->get(OAuthClientProvisioner::class)->provision($app);

        $user = UserFactory::createOne();
        $this->client->loginUser($user);

        $verifier = str_repeat('a', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $params = [
            'response_type' => 'code',
            'client_id' => $app->oauthClientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'bookcases.write',
            'state' => 'xyz',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];

        $crawler = $this->client->request('GET', '/oauth/authorize', $params);
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/oauth/authorize?' . http_build_query($params), [
            '_token' => $token,
            'consent_action' => 'approve',
        ]);
        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $this->assertArrayHasKey('code', $back);

        // Public client → no secret, code_verifier instead.
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app->oauthClientId,
            'redirect_uri' => self::REDIRECT,
            'code_verifier' => $verifier,
            'code' => $back['code'],
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertArrayHasKey('access_token', $this->json());
    }
}

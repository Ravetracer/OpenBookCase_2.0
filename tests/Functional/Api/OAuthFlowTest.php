<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Enums\ApiClientType;
use App\Service\OAuthClientProvisioner;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Functional\FunctionalTestCase;

final class OAuthFlowTest extends FunctionalTestCase
{
    private const REDIRECT = 'https://example.com/callback';

    /** @return array{0:string,1:?string} [clientId, secret] */
    private function provisionClient(ApiClientType $type): array
    {
        $app = ApiApplicationFactory::createOne([
            'clientType' => $type,
            'redirectUris' => [self::REDIRECT],
            'requestedScopes' => ['bookcases.write'],
        ]);
        $secret = static::getContainer()->get(OAuthClientProvisioner::class)->provision($app);

        return [$app->oauthClientId, $secret];
    }

    private function authorizeParams(string $clientId): array
    {
        return [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => 'bookcases.write',
            'state' => 'xyz',
        ];
    }

    public function testAuthorizeRequiresLogin(): void
    {
        [$clientId] = $this->provisionClient(ApiClientType::Confidential);
        $this->client->request('GET', '/oauth/authorize', $this->authorizeParams($clientId));
        // Anonymous → redirected to login by the access_control rule.
        $this->assertResponseRedirects();
    }

    public function testTokenEndpointRejectsEmptyRequest(): void
    {
        $this->client->request('POST', '/oauth/token');
        $this->assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testConsentScreenShownToLoggedInUser(): void
    {
        [$clientId] = $this->provisionClient(ApiClientType::Confidential);
        $this->loginAsUser();

        $crawler = $this->client->request('GET', '/oauth/authorize', $this->authorizeParams($clientId));
        $this->assertResponseIsSuccessful();
        // The consent form carries the approve/deny buttons.
        $this->assertGreaterThan(0, $crawler->filter('button[name="consent_action"]')->count());
    }

    public function testAuthorizationCodeFlowIssuesAccessToken(): void
    {
        [$clientId, $secret] = $this->provisionClient(ApiClientType::Confidential);
        $this->loginAsUser();
        $params = $this->authorizeParams($clientId);

        // 1) Consent screen → read its CSRF token.
        $crawler = $this->client->request('GET', '/oauth/authorize', $params);
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        // 2) Approve → redirect back to the client with an auth code.
        $this->client->request('POST', '/oauth/authorize?' . http_build_query($params), [
            '_token' => $token,
            'consent_action' => 'approve',
        ]);
        $this->assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith(self::REDIRECT, $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);
        $this->assertArrayHasKey('code', $back, 'authorization code returned');
        $this->assertSame('xyz', $back['state'] ?? null);

        // 3) Exchange the code for an access token.
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $secret,
            'redirect_uri' => self::REDIRECT,
            'code' => $back['code'],
        ]);
        $this->assertResponseIsSuccessful();
        $json = $this->json();
        $this->assertArrayHasKey('access_token', $json);
        $this->assertArrayHasKey('refresh_token', $json);
        $this->assertSame('Bearer', $json['token_type']);
    }
}

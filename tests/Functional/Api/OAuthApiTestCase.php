<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\User;
use App\Enums\ApiClientType;
use App\Service\OAuthClientProvisioner;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Functional\FunctionalTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base for /api/v1 functional tests. Mints a real OAuth2 access token for a user
 * with the given scopes (provision confidential client → consent → code → token),
 * and sends authenticated JSON requests with it.
 */
abstract class OAuthApiTestCase extends FunctionalTestCase
{
    protected const REDIRECT = 'https://example.com/cb';

    /** @param string[] $scopes */
    protected function tokenFor(User $user, array $scopes): string
    {
        $app = ApiApplicationFactory::createOne([
            'clientType' => ApiClientType::Confidential,
            'redirectUris' => [self::REDIRECT],
            'requestedScopes' => $scopes,
        ]);
        $secret = static::getContainer()->get(OAuthClientProvisioner::class)->provision($app);

        $this->client->loginUser($user);
        $params = [
            'response_type' => 'code',
            'client_id' => $app->oauthClientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => implode(' ', $scopes),
            'state' => 's',
        ];

        $crawler = $this->client->request('GET', '/oauth/authorize', $params);
        $consentToken = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request('POST', '/oauth/authorize?' . http_build_query($params), [
            '_token' => $consentToken,
            'consent_action' => 'approve',
        ]);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $back);

        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $app->oauthClientId,
            'client_secret' => $secret,
            'redirect_uri' => self::REDIRECT,
            'code' => $back['code'] ?? '',
        ]);

        return (string) ($this->json()['access_token'] ?? '');
    }

    /** @param array<string, mixed> $body */
    protected function api(string $method, string $url, ?string $token = null, array $body = []): Crawler
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        }

        return $this->client->request($method, $url, [], [], $server, $body ? json_encode($body) : null);
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}

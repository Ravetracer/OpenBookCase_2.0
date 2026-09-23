<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\ApiApplication;
use App\Entity\Bookcase;
use App\Entity\User;
use App\Enums\ApiClientType;
use App\Service\ApiApplicationService;
use App\Service\OAuthClientProvisioner;
use App\Service\UserDeletionService;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\Api\OAuthApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Abuse of the public OAuth2 API: missing / insufficient / forged / expired /
 * revoked tokens, tokens of suspended or deleted accounts, and attacks on the
 * authorization-code flow itself (redirect_uri tampering, scope escalation,
 * code replay, PKCE bypass, wrong client secret, consent without CSRF token).
 */
final class OAuthAbuseTest extends OAuthApiTestCase
{
    use SecurityTestTrait;

    private const ALL_SCOPES = ['read', 'bookcases.write', 'bookcases.delete', 'images.write',
        'wishlist.write', 'watchlist.write', 'ratings.write', 'home.write'];

    /** @return iterable<string, array{string, string, string, array<string, mixed>}> */
    public static function writeEndpoints(): iterable
    {
        yield 'create bookcase' => ['POST', '/api/v1/bookcases', 'bookcases.write', ['title' => 'Evil', 'latitude' => 1, 'longitude' => 1]];
        yield 'update bookcase' => ['PATCH', '/api/v1/bookcases/{bc}', 'bookcases.write', ['title' => 'Hacked']];
        yield 'move bookcase' => ['POST', '/api/v1/bookcases/{bc}/position', 'bookcases.write', ['latitude' => 1, 'longitude' => 1]];
        yield 'delete bookcase' => ['DELETE', '/api/v1/bookcases/{bc}', 'bookcases.delete', ['reason' => 'evil']];
        yield 'add caretaker' => ['POST', '/api/v1/bookcases/{bc}/caretakers', 'bookcases.write', ['name' => 'Evil']];
        yield 'update caretaker' => ['PATCH', '/api/v1/bookcases/{bc}/caretakers/{caretaker}', 'bookcases.write', ['name' => 'Evil']];
        yield 'delete caretaker' => ['DELETE', '/api/v1/bookcases/{bc}/caretakers/{caretaker}', 'bookcases.write', []];
        yield 'upload image' => ['POST', '/api/v1/bookcases/{bc}/images', 'images.write', ['author' => 'Evil']];
        yield 'add opening time' => ['POST', '/api/v1/bookcases/{bc}/opening-times', 'bookcases.write', ['openTime' => 'never']];
        yield 'update opening time' => ['PATCH', '/api/v1/bookcases/{bc}/opening-times/{ot}', 'bookcases.write', ['openTime' => 'never']];
        yield 'delete opening time' => ['DELETE', '/api/v1/bookcases/{bc}/opening-times/{ot}', 'bookcases.write', []];
        yield 'set home' => ['POST', '/api/v1/profile/home', 'home.write', ['latitude' => 1, 'longitude' => 1]];
        yield 'upsert rating' => ['PUT', '/api/v1/bookcases/{bc}/rating', 'ratings.write', ['value' => 1]];
        yield 'delete rating' => ['DELETE', '/api/v1/bookcases/{bc}/rating', 'ratings.write', []];
        yield 'watch' => ['POST', '/api/v1/bookcases/{other}/watch', 'watchlist.write', []];
        yield 'unwatch' => ['DELETE', '/api/v1/bookcases/{bc}/watch', 'watchlist.write', []];
        yield 'add wish' => ['POST', '/api/v1/bookcases/{bc}/wishlist', 'wishlist.write', ['title' => 'Evil']];
    }

    // ── Token presence / scope ─────────────────────────────────────────────

    #[DataProvider('writeEndpoints')]
    public function testWriteWithoutTokenIsUnauthorized(string $method, string $path, string $scope, array $body): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $before = $this->dbSnapshot();

        $this->api($method, $url, null, $body);

        $this->assertSame(401, $this->statusCode(), "$method $path without token");
        $this->assertDbUnchanged($before, "$method $path without token");
    }

    #[DataProvider('writeEndpoints')]
    public function testWriteWithEveryScopeButTheRequiredOneIsForbidden(string $method, string $path, string $scope, array $body): void
    {
        $victim = UserFactory::createOne();
        $url = $this->fill($path, $this->seedWorld($victim));
        $token = $this->tokenFor($victim, array_values(array_diff(self::ALL_SCOPES, [$scope])));
        $this->assertNotSame('', $token);
        $before = $this->dbSnapshot();

        $this->api($method, $url, $token, $body);

        $this->assertSame(403, $this->statusCode(), "$method $path without scope $scope");
        $this->assertDbUnchanged($before, "$method $path without scope $scope");
    }

    /** @return iterable<string, array{string}> */
    public static function bogusTokens(): iterable
    {
        yield 'garbage' => ['not-a-token'];
        yield 'three dots' => ['a.b.c'];
        yield 'alg none' => [self::b64(['alg' => 'none', 'typ' => 'JWT']) . '.' . self::b64(['sub' => 'x', 'scopes' => ['bookcases.write']]) . '.'];
        yield 'empty' => [''];
    }

    #[DataProvider('bogusTokens')]
    public function testBogusBearerTokenIsUnauthorizedNotServerError(string $token): void
    {
        $this->api('POST', '/api/v1/bookcases', $token, ['title' => 'Evil', 'latitude' => 1, 'longitude' => 1]);

        $this->assertSame(401, $this->statusCode());
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        [, $payload] = explode('.', $token);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        // Control: our re-signing is faithful — the untouched claims still work.
        $this->api('POST', '/api/v1/bookcases', $this->signJwt($claims), ['title' => 'Control', 'latitude' => 1, 'longitude' => 1]);
        $this->assertSame(201, $this->statusCode(), 're-signed original token should be accepted');

        $claims['iat'] = $claims['nbf'] = time() - 7200;
        $claims['exp'] = time() - 3600;

        $this->api('POST', '/api/v1/bookcases', $this->signJwt($claims), ['title' => 'Evil', 'latitude' => 1, 'longitude' => 1]);

        $this->assertSame(401, $this->statusCode());
    }

    public function testTokenSignedWithForeignKeyIsRejected(): void
    {
        $token = $this->tokenFor(UserFactory::createOne(), ['bookcases.write']);
        [$header, $payload] = explode('.', $token);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_sign("$header.$payload", $sig, $key, OPENSSL_ALGO_SHA256);

        $this->api('POST', '/api/v1/bookcases', "$header.$payload." . self::b64url($sig), ['title' => 'Evil', 'latitude' => 1, 'longitude' => 1]);

        $this->assertSame(401, $this->statusCode());
    }

    // ── Revocation / account state ─────────────────────────────────────────

    public function testRevokedApplicationTokensStopWorking(): void
    {
        $user = UserFactory::createOne();
        [$app, $secret] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $tokens = $this->exchange($app, $this->authorizeCode($app, $user, ['bookcases.write']), $secret);
        $this->assertArrayHasKey('access_token', $tokens);

        $this->revoke($app);

        $this->api('POST', '/api/v1/bookcases', $tokens['access_token'], ['title' => 'Evil', 'latitude' => 1, 'longitude' => 1]);
        $this->assertSame(401, $this->statusCode(), 'access token of a revoked app');

        $this->assertArrayNotHasKey('access_token', $this->refresh($app, $secret, $tokens['refresh_token']), 'refresh token of a revoked app');

        // A fresh authorization round must not yield a usable token either.
        $code = $this->authorizeCode($app, $user, ['bookcases.write']);
        $this->assertArrayNotHasKey('access_token', $this->exchange($app, $code, $secret), 'new token for a revoked app');
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]));
    }

    public function testRevokedClientIsNotOfferedConsentNorIssuedACode(): void
    {
        [$app] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $this->revoke($app);

        $this->assertNull(
            $this->authorizeCode($app, UserFactory::createOne(), ['bookcases.write']),
            'a disabled client must be refused at /oauth/authorize (no consent screen, no code)',
        );
    }

    public function testSuspendedUsersTokensAreRejected(): void
    {
        $user = UserFactory::createOne();
        $bc = BookcaseFactory::createOne(['title' => 'Untouched']);
        [$app, $secret] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $tokens = $this->exchange($app, $this->authorizeCode($app, $user, ['bookcases.write']), $secret);
        $this->assertArrayHasKey('access_token', $tokens);

        $fresh = $this->em()->getRepository(User::class)->find($user->id);
        $fresh->isSuspended = true;
        $this->em()->flush();
        $before = $this->dbSnapshot();

        $this->api('PATCH', '/api/v1/bookcases/' . $bc->id, $tokens['access_token'], ['title' => 'Vandalised by a suspended account']);

        $this->assertContains($this->statusCode(), [401, 403], 'a suspended account must not act through the API');
        $this->assertDbUnchanged($before, 'API write by a suspended account');

        $this->assertArrayNotHasKey(
            'access_token',
            $this->refresh($app, $secret, $tokens['refresh_token']),
            'a suspended account must not be able to mint fresh access tokens',
        );
    }

    public function testDeletedUsersTokenIsUnauthorizedNotServerError(): void
    {
        $user = UserFactory::createOne();
        $token = $this->tokenFor($user, ['bookcases.write']);
        static::getContainer()->get(UserDeletionService::class)->deleteUser($user->id);

        $this->api('POST', '/api/v1/bookcases', $token, ['title' => 'Ghost', 'latitude' => 1, 'longitude' => 1]);

        $this->assertSame(401, $this->statusCode());
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]));
    }

    // ── Authorization-code flow ────────────────────────────────────────────

    /** @return iterable<string, array{string}> */
    public static function foreignRedirectUris(): iterable
    {
        yield 'other host' => ['https://evil.example/cb'];
        yield 'userinfo trick' => ['https://example.com/cb@evil.example'];
        yield 'suffix host' => ['https://example.com.evil.example/cb'];
        yield 'path traversal' => ['https://example.com/cb/../../evil'];
        yield 'extra path' => ['https://example.com/cb/evil'];
        yield 'scheme downgrade' => ['http://example.com/cb'];
    }

    #[DataProvider('foreignRedirectUris')]
    public function testAuthorizeNeverRedirectsToAnUnregisteredUri(string $redirectUri): void
    {
        [$app] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $this->client->loginUser(UserFactory::createOne());
        $params = $this->authorizeParams($app, ['bookcases.write'], ['redirect_uri' => $redirectUri]);

        $crawler = $this->client->request('GET', '/oauth/authorize', $params);
        $this->assertStringNotContainsString('evil', (string) $this->client->getResponse()->headers->get('Location'));

        // Even a forced "approve" post must not send a code to the foreign URI.
        $token = $crawler->filter('input[name="_token"]')->count() ? $crawler->filter('input[name="_token"]')->attr('value') : 'x';
        $this->client->request('POST', '/oauth/authorize?' . http_build_query($params), ['_token' => $token, 'consent_action' => 'approve']);
        $location = (string) $this->client->getResponse()->headers->get('Location');
        $this->assertFalse(str_starts_with($location, $redirectUri), "code must never be delivered to $redirectUri (got: $location)");
    }

    public function testCodeCannotBeRedeemedWithADifferentRedirectUri(): void
    {
        [$app, $secret] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write'], [self::REDIRECT, 'https://example.com/other']);
        $code = $this->authorizeCode($app, UserFactory::createOne(), ['bookcases.write']);

        $json = $this->exchange($app, $code, $secret, ['redirect_uri' => 'https://example.com/other']);

        $this->assertArrayNotHasKey('access_token', $json);
    }

    public function testScopeCannotBeEscalatedBeyondTheApprovedApplication(): void
    {
        [$app, $secret] = $this->provisionApp(ApiClientType::Confidential, ['wishlist.write']);
        $code = $this->authorizeCode($app, UserFactory::createOne(), ['wishlist.write', 'bookcases.write', 'bookcases.delete']);

        if ($code !== null) {
            $json = $this->exchange($app, $code, $secret);
            if (isset($json['access_token'])) {
                $this->api('POST', '/api/v1/bookcases', $json['access_token'], ['title' => 'Escalated', 'latitude' => 1, 'longitude' => 1]);
                $this->assertSame(403, $this->statusCode(), 'an unapproved scope must not be usable');
            }
        }
        $this->assertSame(0, $this->em()->getRepository(Bookcase::class)->count([]));
    }

    public function testAuthorizationCodeCannotBeReplayed(): void
    {
        [$app, $secret] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $code = $this->authorizeCode($app, UserFactory::createOne(), ['bookcases.write']);

        $this->assertArrayHasKey('access_token', $this->exchange($app, $code, $secret));
        $this->assertArrayNotHasKey('access_token', $this->exchange($app, $code, $secret), 'a used code must not be redeemable again');
    }

    public function testConfidentialClientWithWrongSecretGetsNoToken(): void
    {
        [$app] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $code = $this->authorizeCode($app, UserFactory::createOne(), ['bookcases.write']);

        $this->assertArrayNotHasKey('access_token', $this->exchange($app, $code, 'wrong-secret'));
        $this->assertSame(401, $this->statusCode());
        $this->assertArrayNotHasKey('access_token', $this->exchange($app, $code, null), 'secret omitted');
    }

    public function testPublicClientCannotSkipPkce(): void
    {
        [$app] = $this->provisionApp(ApiClientType::PublicClient, ['bookcases.write']);

        $this->assertNull(
            $this->authorizeCode($app, UserFactory::createOne(), ['bookcases.write']),
            'a public client must not obtain a code without a code_challenge',
        );
    }

    /** @return iterable<string, array{?string}> */
    public static function badVerifiers(): iterable
    {
        yield 'missing verifier' => [null];
        yield 'wrong verifier' => [str_repeat('b', 64)];
    }

    #[DataProvider('badVerifiers')]
    public function testPublicClientNeedsTheMatchingCodeVerifier(?string $verifier): void
    {
        [$app] = $this->provisionApp(ApiClientType::PublicClient, ['bookcases.write']);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', str_repeat('a', 64), true)), '+/', '-_'), '=');
        $code = $this->authorizeCode($app, UserFactory::createOne(), ['bookcases.write'], [
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $this->assertNotNull($code);

        $json = $this->exchange($app, $code, null, $verifier !== null ? ['code_verifier' => $verifier] : []);

        $this->assertArrayNotHasKey('access_token', $json);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function nonConsents(): iterable
    {
        yield 'no csrf token' => [['consent_action' => 'approve']];
        yield 'forged csrf token' => [['consent_action' => 'approve', '_token' => 'forged']];
        yield 'explicit deny' => [['consent_action' => 'deny', '_token' => '__valid__']];
    }

    #[DataProvider('nonConsents')]
    public function testNoCodeWithoutAValidConsent(array $post): void
    {
        [$app] = $this->provisionApp(ApiClientType::Confidential, ['bookcases.write']);
        $this->client->loginUser(UserFactory::createOne());
        $params = $this->authorizeParams($app, ['bookcases.write']);
        $crawler = $this->client->request('GET', '/oauth/authorize', $params);
        if (($post['_token'] ?? null) === '__valid__') {
            $post['_token'] = $crawler->filter('input[name="_token"]')->attr('value');
        }

        $this->client->request('POST', '/oauth/authorize?' . http_build_query($params), $post);

        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $back);
        $this->assertArrayNotHasKey('code', $back);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param string[] $scopes
     * @param string[] $redirectUris
     *
     * @return array{ApiApplication, ?string}
     */
    private function provisionApp(ApiClientType $type, array $scopes, array $redirectUris = [self::REDIRECT]): array
    {
        $app = ApiApplicationFactory::new()->approved()->create([
            'clientType' => $type,
            'redirectUris' => $redirectUris,
            'requestedScopes' => $scopes,
        ]);
        $secret = static::getContainer()->get(OAuthClientProvisioner::class)->provision($app);

        return [$app, $secret];
    }

    /** @param string[] $scopes */
    private function authorizeParams(ApiApplication $app, array $scopes, array $extra = []): array
    {
        return $extra + [
            'response_type' => 'code',
            'client_id' => $app->oauthClientId,
            'redirect_uri' => self::REDIRECT,
            'scope' => implode(' ', $scopes),
            'state' => 's',
        ];
    }

    /**
     * Run consent as $user and return the issued code, or null when none was issued.
     *
     * @param string[] $scopes
     */
    private function authorizeCode(ApiApplication $app, User $user, array $scopes, array $extra = []): ?string
    {
        $this->client->loginUser($user);
        $params = $this->authorizeParams($app, $scopes, $extra);

        $crawler = $this->client->request('GET', '/oauth/authorize', $params);
        $tokenInput = $crawler->filter('input[name="_token"]');
        if ($tokenInput->count() === 0) {
            return null;
        }

        $this->client->request('POST', '/oauth/authorize?' . http_build_query($params), [
            '_token' => $tokenInput->attr('value'),
            'consent_action' => 'approve',
        ]);
        parse_str((string) parse_url((string) $this->client->getResponse()->headers->get('Location'), PHP_URL_QUERY), $back);

        return $back['code'] ?? null;
    }

    /** @return array<string, mixed> token endpoint JSON */
    private function exchange(ApiApplication $app, ?string $code, ?string $secret, array $extra = []): array
    {
        $params = $extra + [
            'grant_type' => 'authorization_code',
            'client_id' => $app->oauthClientId,
            'redirect_uri' => self::REDIRECT,
            'code' => (string) $code,
        ];
        if ($secret !== null) {
            $params['client_secret'] = $secret;
        }
        $this->client->request('POST', '/oauth/token', $params);

        return $this->json();
    }

    private function revoke(ApiApplication $app): void
    {
        static::getContainer()->get(ApiApplicationService::class)->revoke(
            $this->em()->getRepository(ApiApplication::class)->find($app->id),
            UserFactory::new()->admin()->create(),
            'abuse',
        );
    }

    /** @return array<string, mixed> token endpoint JSON */
    private function refresh(ApiApplication $app, ?string $secret, string $refreshToken): array
    {
        $this->client->request('POST', '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $app->oauthClientId,
            'client_secret' => (string) $secret,
            'refresh_token' => $refreshToken,
        ]);

        return $this->json();
    }

    /** RS256-sign arbitrary claims with the server's own private key. */
    private function signJwt(array $claims): string
    {
        $key = openssl_pkey_get_private(
            'file://' . static::getContainer()->getParameter('kernel.project_dir') . '/config/jwt/private.pem',
            (string) ($_SERVER['OAUTH_PASSPHRASE'] ?? $_ENV['OAUTH_PASSPHRASE'] ?? ''),
        );
        $this->assertNotFalse($key, 'test private key must be readable');
        $unsigned = self::b64(['typ' => 'JWT', 'alg' => 'RS256']) . '.' . self::b64($claims);
        openssl_sign($unsigned, $sig, $key, OPENSSL_ALGO_SHA256);

        return $unsigned . '.' . self::b64url($sig);
    }

    private static function b64(array $data): string
    {
        return self::b64url((string) json_encode($data));
    }

    private static function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

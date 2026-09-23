<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CSRF coverage for the state-changing endpoints not already exercised by
 * ApiCsrfTest / ProfileControllerTest / AdminControllerTest: every internal
 * /api/bookcase write under a cross-site fetch, the remaining profile and
 * API-application endpoints, every per-user admin action, login, registration
 * and password reset. A forged request must change nothing.
 */
final class CsrfCoverageTest extends FunctionalTestCase
{
    use SecurityTestTrait;

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function internalApiWrites(): iterable
    {
        yield 'create' => ['POST', '/api/bookcase/create', ['bookcase_create' => ['title' => 'CSRF', 'position' => ['latitude' => 1, 'longitude' => 1]]]];
        yield 'save' => ['POST', '/api/bookcase/{bc}/save', ['bookcase' => ['title' => 'CSRF']]];
        yield 'watch add' => ['POST', '/api/bookcase/{other}/watch', []];
        yield 'watch remove' => ['DELETE', '/api/bookcase/{bc}/watch', []];
        yield 'move' => ['POST', '/api/bookcase/{bc}/position', ['latitude' => 1, 'longitude' => 1]];
        yield 'image upload' => ['POST', '/api/bookcase/{bc}/image', ['author' => 'CSRF']];
        yield 'image alt' => ['POST', '/api/bookcase/{bc}/image/{img}/alt', ['altText' => 'CSRF']];
        yield 'image rotate' => ['POST', '/api/bookcase/{bc}/image/{img}/rotate', ['direction' => 'cw']];
        yield 'image delete' => ['DELETE', '/api/bookcase/{bc}/image/{img}', []];
        yield 'wish add' => ['POST', '/api/bookcase/{bc}/wishlist', ['title' => 'CSRF']];
        yield 'wish status' => ['POST', '/api/bookcase/{bc}/wishlist/{wish}/status', ['action' => 'drop']];
        yield 'wish delete' => ['DELETE', '/api/bookcase/{bc}/wishlist/{wish}', []];
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function crossOriginSignals(): iterable
    {
        yield 'cross-site fetch' => [['HTTP_SEC_FETCH_SITE' => 'cross-site']];
        yield 'same-site (sibling subdomain) fetch' => [['HTTP_SEC_FETCH_SITE' => 'same-site']];
        yield 'foreign Origin' => [['HTTP_ORIGIN' => 'https://evil.example']];
        yield 'foreign Referer only' => [['HTTP_REFERER' => 'https://evil.example/page']];
    }

    #[DataProvider('internalApiWrites')]
    public function testCrossSiteWriteToInternalApiIsBlocked(string $method, string $path, array $params): void
    {
        // The victim is the logged-in user, so every write would otherwise succeed on their behalf.
        $victim = UserFactory::createOne();
        $url = $this->fill($path, $this->seedWorld($victim));
        $this->client->loginUser($victim);

        foreach (self::crossOriginSignals() as $label => [$server]) {
            $before = $this->dbSnapshot();
            $this->client->request($method, $url, $params, [], $server);

            $this->assertResponseStatusCodeSame(403, "$method $path with $label");
            $this->assertDbUnchanged($before, "$method $path with $label");
        }
    }

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function tokenProtectedUserEndpoints(): iterable
    {
        yield 'notifications' => ['POST', '/profile/notifications', ['channel' => 'email']];
        yield 'language' => ['POST', '/profile/language', ['locale' => 'fr']];
        yield 'api reply' => ['POST', '/profile/api/{app}/reply', ['body' => 'forged']];
        yield 'api ack secret' => ['POST', '/profile/api/{app}/ack-secret', []];
        yield 'api apply' => ['POST', '/profile/api/apply', ['appName' => 'Forged', 'useCase' => 'A long enough forged use case text.', 'clientType' => 'public']];
    }

    #[DataProvider('tokenProtectedUserEndpoints')]
    public function testForgedTokenOnProfileEndpointChangesNothing(string $method, string $path, array $params): void
    {
        UserFactory::new()->admin()->create();
        $victim = UserFactory::createOne();
        $url = $this->fill($path, $this->seedWorld($victim));
        $this->client->loginUser($victim);

        foreach ([[], ['_token' => 'forged']] as $token) {
            $before = $this->dbSnapshot();
            $this->client->request($method, $url, $params + $token);

            $this->assertResponseStatusCodeSame(400, "$path with " . ($token ? 'forged' : 'missing') . ' token');
            $this->assertDbUnchanged($before, "$path with forged token");
        }
    }

    /** @return iterable<string, array{string}> */
    public static function adminUserActions(): iterable
    {
        yield 'email' => ['/admin/users/{user}/email'];
        yield 'roles' => ['/admin/users/{user}/roles'];
        yield 'suspend' => ['/admin/users/{user}/suspend'];
        yield 'resend verification' => ['/admin/users/{user}/resend-verification'];
        yield 'reset link' => ['/admin/users/{user}/reset-link'];
        yield 'delete' => ['/admin/users/{user}/delete'];
        yield 'approve' => ['/admin/api-applications/{app}/approve'];
        yield 'deny' => ['/admin/api-applications/{app}/deny'];
        yield 'revoke' => ['/admin/api-applications/{app}/revoke'];
        yield 'message' => ['/admin/api-applications/{app}/message'];
    }

    #[DataProvider('adminUserActions')]
    public function testForgedTokenOnAdminActionChangesNothing(string $path): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $this->client->enableProfiler();
        $before = $this->dbSnapshot();

        $this->client->request('POST', $url, [
            '_token' => 'forged', 'email' => 'new@example.com', 'roles' => ['ROLE_ADMIN'], 'suspend' => '1',
            'reason' => 'forged', 'body' => 'forged',
        ]);

        $this->assertResponseRedirects();
        $this->assertDbUnchanged($before, "admin POST $path with forged token");
        $this->assertEmailCount(0);
    }

    public function testCrossOriginLoginIsRejected(): void
    {
        UserFactory::createOne(['username' => 'loginvictim']);

        $this->client->request('POST', '/login', [
            'username' => 'loginvictim',
            'password' => UserFactory::PLAIN_PASSWORD,
            '_csrf_token' => 'csrf-token',
        ], [], ['HTTP_ORIGIN' => 'https://evil.example', 'HTTP_REFERER' => 'https://evil.example/']);

        $this->assertNull(static::getContainer()->get('security.token_storage')->getToken(), 'login CSRF must be rejected');
    }

    public function testCrossOriginRegistrationIsRejected(): void
    {
        $form = $this->client->request('GET', '/register')->filter('form')->form();
        $form['registration_form[username]'] = 'csrfsignup';
        $form['registration_form[email]'] = 'csrfsignup@example.com';
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        $form['registration_form[agreeTerms]']->tick();

        $this->client->request('POST', $form->getUri(), $form->getPhpValues(), [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_REFERER' => 'https://evil.example/',
        ]);

        $this->assertNull($this->em()->getRepository(User::class)->findOneBy(['username' => 'csrfsignup']));
    }

    public function testForgedTokenOnPasswordResetChangesNothing(): void
    {
        $raw = bin2hex(random_bytes(16));
        $user = UserFactory::createOne([
            'resetTokenHash' => hash('sha256', $raw),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+1 hour'),
        ]);
        $before = $this->dbSnapshot();

        $this->client->request('POST', '/reset-password/' . $raw, [
            '_token' => 'forged',
            'password' => 'attacker-chosen-pw',
            'password_repeat' => 'attacker-chosen-pw',
        ]);

        $this->assertDbUnchanged($before, 'password reset with forged CSRF token');
        $this->assertNotNull($this->em()->getRepository(User::class)->find($user->id)->resetTokenHash, 'token must stay usable');
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Functional\Security;

use App\Entity\User;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Access-control matrix for the cookie-authenticated website: every login-gated
 * or state-changing route must reject anonymous visitors, every /admin route
 * must reject normal users, and suspended / unverified accounts must not be able
 * to get (or keep) a session. Each rejection must also leave the DB untouched.
 */
final class AccessControlTest extends FunctionalTestCase
{
    use SecurityTestTrait;

    /** @return iterable<string, array{string, string, array<string, mixed>, ?array<string, mixed>}> */
    public static function userOnlyRoutes(): iterable
    {
        $bcForm = ['title' => 'Hacked', 'entryType' => 'bookcase', 'position' => ['latitude' => 1, 'longitude' => 2]];

        yield 'quick-add form' => ['GET', '/api/bookcase/new', [], null];
        yield 'create' => ['POST', '/api/bookcase/create', ['bookcase_create' => $bcForm], null];
        yield 'edit form' => ['GET', '/api/bookcase/{bc}/edit', [], null];
        yield 'photo manager' => ['GET', '/api/bookcase/{bc}/photos', [], null];
        yield 'save' => ['POST', '/api/bookcase/{bc}/save', ['bookcase' => $bcForm], null];
        yield 'soft delete' => ['DELETE', '/api/bookcase/{bc}', [], ['reason' => 'anon']];
        yield 'watch add' => ['POST', '/api/bookcase/{bc}/watch', [], null];
        yield 'watch remove' => ['DELETE', '/api/bookcase/{bc}/watch', [], null];
        yield 'rate' => ['POST', '/api/bookcase/{bc}/rating', ['value' => 1], null];
        yield 'move' => ['POST', '/api/bookcase/{bc}/position', ['latitude' => 1, 'longitude' => 1], null];
        yield 'image upload' => ['POST', '/api/bookcase/{bc}/image', ['author' => 'anon'], null];
        yield 'image alt' => ['POST', '/api/bookcase/{bc}/image/{img}/alt', ['altText' => 'pwned'], null];
        yield 'image rotate' => ['POST', '/api/bookcase/{bc}/image/{img}/rotate', ['direction' => 'cw'], null];
        yield 'image delete' => ['DELETE', '/api/bookcase/{bc}/image/{img}', [], null];
        yield 'wish add' => ['POST', '/api/bookcase/{bc}/wishlist', ['title' => 'Anon wish'], null];
        yield 'wish status' => ['POST', '/api/bookcase/{bc}/wishlist/{wish}/status', ['action' => 'drop'], null];
        yield 'wish delete' => ['DELETE', '/api/bookcase/{bc}/wishlist/{wish}', [], null];
        yield 'inbox' => ['GET', '/messages', [], null];
        yield 'profile wishlist' => ['GET', '/profile/wishlist', [], null];
        yield 'profile email' => ['POST', '/profile/email', ['email' => 'anon@example.com'], null];
        yield 'profile notifications' => ['POST', '/profile/notifications', ['channel' => 'email'], null];
        yield 'profile language' => ['POST', '/profile/language', ['locale' => 'de'], null];
        yield 'profile home' => ['POST', '/profile/home', ['latitude' => 1, 'longitude' => 1, 'enabled' => 1], null];
        yield 'profile delete' => ['POST', '/profile/delete', [], null];
        yield 'api apply' => ['POST', '/profile/api/apply', ['appName' => 'x', 'useCase' => 'y', 'clientType' => 'public'], null];
        yield 'api reply' => ['POST', '/profile/api/{app}/reply', ['body' => 'anon'], null];
        yield 'api ack secret' => ['POST', '/profile/api/{app}/ack-secret', [], null];
        yield 'oauth consent' => ['GET', '/oauth/authorize', ['response_type' => 'code', 'client_id' => 'x'], null];
    }

    /** @return iterable<string, array{string, string}> */
    public static function adminRoutes(): iterable
    {
        yield 'dashboard' => ['GET', '/admin'];
        yield 'users' => ['GET', '/admin/users'];
        yield 'user detail' => ['GET', '/admin/users/{user}'];
        yield 'api usage' => ['GET', '/admin/api-usage'];
        yield 'api applications' => ['GET', '/admin/api-applications'];
        yield 'api application' => ['GET', '/admin/api-applications/{app}'];
        yield 'users bulk' => ['POST', '/admin/users/bulk'];
        yield 'user email' => ['POST', '/admin/users/{user}/email'];
        yield 'user roles' => ['POST', '/admin/users/{user}/roles'];
        yield 'user suspend' => ['POST', '/admin/users/{user}/suspend'];
        yield 'user resend' => ['POST', '/admin/users/{user}/resend-verification'];
        yield 'user reset link' => ['POST', '/admin/users/{user}/reset-link'];
        yield 'user delete' => ['POST', '/admin/users/{user}/delete'];
        yield 'api approve' => ['POST', '/admin/api-applications/{app}/approve'];
        yield 'api deny' => ['POST', '/admin/api-applications/{app}/deny'];
        yield 'api revoke' => ['POST', '/admin/api-applications/{app}/revoke'];
        yield 'api message' => ['POST', '/admin/api-applications/{app}/message'];
    }

    /**
     * @param array<string, mixed>      $params
     * @param array<string, mixed>|null $json
     */
    #[DataProvider('userOnlyRoutes')]
    public function testAnonymousIsRejectedAndChangesNothing(string $method, string $path, array $params, ?array $json): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $before = $this->dbSnapshot();

        if ($json !== null) {
            $this->client->request($method, $url, [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($json));
        } else {
            $this->client->request($method, $url, $params);
        }

        $this->assertRejected("anonymous $method $path");
        $this->assertDbUnchanged($before, "anonymous $method $path");
    }

    #[DataProvider('adminRoutes')]
    public function testAnonymousCannotReachAdmin(string $method, string $path): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $before = $this->dbSnapshot();

        $this->client->request($method, $url, $this->adminPayload());

        $this->assertRejected("anonymous $method $path");
        $this->assertDbUnchanged($before, "anonymous $method $path");
    }

    #[DataProvider('adminRoutes')]
    public function testNormalUserIsForbiddenFromAdmin(string $method, string $path): void
    {
        $url = $this->fill($path, $this->seedWorld());
        $this->loginAsUser();
        $before = $this->dbSnapshot();

        $this->client->request($method, $url, $this->adminPayload());

        $this->assertResponseStatusCodeSame(403, "$method $path as ROLE_USER");
        $this->assertDbUnchanged($before, "ROLE_USER $method $path");
    }

    public function testSuspendedUserCannotLogIn(): void
    {
        UserFactory::createOne(['username' => 'suspended', 'isSuspended' => true]);

        $this->submitLogin('suspended');

        $this->assertNull($this->currentUser(), 'a suspended account must not authenticate');
    }

    public function testUnverifiedUserCannotLogIn(): void
    {
        UserFactory::new()->unverified()->create(['username' => 'unverified']);

        $this->submitLogin('unverified');

        $this->assertNull($this->currentUser(), 'an unverified account must not authenticate');
    }

    public function testSuspensionEndsAnExistingSession(): void
    {
        $user = $this->loginAsUser();
        $bc = BookcaseFactory::createOne();

        // Admin suspends the account while the user still holds a live session.
        $this->suspend((string) $user->username);
        $before = $this->dbSnapshot();

        $this->client->request('POST', '/api/bookcase/' . $bc->id . '/rating', ['value' => 1]);

        $this->assertRejected('suspended user with a pre-existing session');
        $this->assertDbUnchanged($before, 'suspended user with a pre-existing session');
    }

    public function testSuspensionBlocksRememberMeLogin(): void
    {
        UserFactory::createOne(['username' => 'remembered']);
        $this->submitLogin('remembered', ['_remember_me' => 'on']);
        $this->assertNotNull($this->client->getCookieJar()->get('REMEMBERME'), 'remember-me cookie expected');

        $this->suspend('remembered');

        // Drop the session: only the remember-me cookie could re-authenticate now.
        $this->client->getCookieJar()->expire('MOCKSESSID');
        $this->client->request('GET', '/');

        $this->assertNull($this->currentUser(), 'remember-me must not revive a suspended account');
    }

    private function suspend(string $username): void
    {
        $user = $this->em()->getRepository(User::class)->findOneBy(['username' => $username]);
        $user->isSuspended = true;
        $this->em()->flush();
    }

    /** Harmless admin-form payload with a bogus CSRF token. */
    private function adminPayload(): array
    {
        return ['_token' => 'forged', 'roles' => ['ROLE_ADMIN'], 'suspend' => '1', 'email' => 'x@example.com',
            'reason' => 'r', 'body' => 'b', 'action' => 'delete', 'ids' => []];
    }

    /** Post the real login form (same-host Referer satisfies the stateless CSRF check). */
    private function submitLogin(string $username, array $extra = []): void
    {
        $this->client->request('POST', '/login', [
            'username' => $username,
            'password' => UserFactory::PLAIN_PASSWORD,
            '_csrf_token' => 'csrf-token',
        ] + $extra, [], ['HTTP_REFERER' => 'http://localhost/']);
    }

    private function currentUser(): ?User
    {
        $user = static::getContainer()->get('security.token_storage')->getToken()?->getUser();

        return $user instanceof User ? $user : null;
    }
}

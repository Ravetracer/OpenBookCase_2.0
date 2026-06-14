<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Tests\Factory\UserFactory;

final class SecurityTest extends FunctionalTestCase
{
    public function testLoginPageRedirectsToIndex(): void
    {
        // SecurityController::login just sets flashes + redirects to app_index;
        // the actual form lives in base.html.twig on the homepage.
        $this->client->request('GET', '/login');
        $this->assertResponseRedirects('/');
    }

    public function testSuccessfulLoginAuthenticatesAndRedirects(): void
    {
        // Lowercase username so it survives the authenticator's strtolower().
        UserFactory::createOne([
            'username' => 'loginuser',
            'email' => 'loginuser@example.com',
        ]);

        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action="/login"]')->form([
            'username' => 'loginuser',
            'password' => UserFactory::PLAIN_PASSWORD,
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/');
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();

        $token = static::getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token, 'expected an authenticated token after login');
        $this->assertInstanceOf(User::class, $token->getUser());
        $this->assertSame('loginuser', $token->getUser()->getUserIdentifier());
    }

    public function testLoginWithEmailIdentifierWorks(): void
    {
        UserFactory::createOne([
            'username' => 'byemail',
            'email' => 'byemail@example.com',
        ]);

        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/login"]')->form([
            'username' => 'byemail@example.com',
            'password' => UserFactory::PLAIN_PASSWORD,
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/');
        $token = static::getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token);
        $this->assertInstanceOf(User::class, $token->getUser());
    }

    public function testFailedLoginWithWrongPasswordDoesNotAuthenticate(): void
    {
        UserFactory::createOne([
            'username' => 'wrongpw',
            'email' => 'wrongpw@example.com',
        ]);

        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/login"]')->form([
            'username' => 'wrongpw',
            'password' => 'totally-wrong',
        ]);
        $this->client->submit($form);

        // Authenticator redirects back to the login route on failure.
        $this->assertResponseRedirects();

        $token = static::getContainer()->get('security.token_storage')->getToken();
        $this->assertNull($token, 'wrong password must not authenticate');
    }

    public function testLogoutDeauthenticates(): void
    {
        $user = UserFactory::createOne();
        $this->client->loginUser($user);

        // Confirm we're authenticated first.
        $this->client->request('GET', '/');
        $this->assertNotNull(static::getContainer()->get('security.token_storage')->getToken());

        $this->client->request('GET', '/logout');
        // Logout redirects (firewall-handled).
        $this->assertResponseRedirects();
    }

    public function testLegacyUserMigratesOnLogin(): void
    {
        $email = 'legacy@example.com';
        $plain = 'legacy-secret';
        $legacyHash = md5(substr(hash('sha256', $email), 5, 15).hash('sha512', $plain));

        UserFactory::new()->legacy($legacyHash)->create([
            'username' => 'legacyuser',
            'email' => $email,
            'isVerified' => true,
            'legacyId' => 999,
        ]);

        $crawler = $this->client->request('GET', '/');
        $form = $crawler->filter('form[action="/login"]')->form([
            'username' => 'legacyuser',
            'password' => $plain,
        ]);
        $this->client->submit($form);

        $this->assertResponseRedirects('/');

        $token = static::getContainer()->get('security.token_storage')->getToken();
        $this->assertNotNull($token, 'legacy login should authenticate with the legacy hash');

        // Re-fetch fresh from the DB to confirm the migration was persisted.
        static::getContainer()->get('doctrine')->getManager()->clear();
        /** @var UserRepository $repo */
        $repo = static::getContainer()->get(UserRepository::class);
        $migrated = $repo->findOneBy(['username' => 'legacyuser']);

        $this->assertNotNull($migrated);
        $this->assertFalse($migrated->legacyUser, 'legacyUser should be cleared after migration');
        $this->assertTrue($migrated->legacyMigrated, 'legacyMigrated should be set after migration');
        $this->assertNotEmpty($migrated->password, 'a bcrypt password should be stored after migration');

        // The stored hash must validate against the plaintext via the hasher.
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $this->assertTrue(
            $hasher->isPasswordValid($migrated, $plain),
            'migrated bcrypt password should validate the plaintext',
        );
    }
}

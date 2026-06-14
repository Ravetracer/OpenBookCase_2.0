<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class ResetPasswordControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    // ---------------------------------------------------------------------
    // GET/POST /forgot-password
    // ---------------------------------------------------------------------

    public function testForgotPasswordPageLoads(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('form')->count());
    }

    public function testForgotPasswordKnownEmailSendsResetMail(): void
    {
        $this->client->enableProfiler();
        UserFactory::createOne(['email' => 'known@example.com']);

        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'known@example.com';
        $this->client->submit($form);

        // Always the same "check your inbox" screen (no enumeration), rendered 200.
        $this->assertResponseIsSuccessful();
        self::assertEmailCount(1);

        // The token + expiry were stored.
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'known@example.com']);
        $this->assertNotNull($user->resetTokenHash);
        $this->assertNotNull($user->resetTokenExpiresAt);
    }

    public function testForgotPasswordUnknownEmailSendsNoMailButSameScreen(): void
    {
        $this->client->enableProfiler();

        $crawler = $this->client->request('GET', '/forgot-password');
        $form = $crawler->filter('form')->form();
        $form['email'] = 'nobody@example.com';
        $this->client->submit($form);

        $this->assertResponseIsSuccessful();
        self::assertEmailCount(0);
    }

    public function testForgotPasswordInvalidCsrfRedirects(): void
    {
        UserFactory::createOne(['email' => 'csrf@example.com']);
        $this->client->request('POST', '/forgot-password', [
            '_token' => 'bogus',
            'email' => 'csrf@example.com',
        ]);
        $this->assertResponseRedirects('/forgot-password');
    }

    // ---------------------------------------------------------------------
    // GET/POST /reset-password/{token}
    // ---------------------------------------------------------------------

    public function testResetWithUnknownTokenRedirects(): void
    {
        $this->client->request('GET', '/reset-password/this-token-does-not-exist');
        $this->assertResponseRedirects('/forgot-password');
    }

    public function testResetWithExpiredTokenRedirects(): void
    {
        UserFactory::createOne([
            'email' => 'expired@example.com',
            'resetTokenHash' => hash('sha256', 'expired-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('-1 hour'),
        ]);

        $this->client->request('GET', '/reset-password/expired-token');
        $this->assertResponseRedirects('/forgot-password');
    }

    public function testResetFormLoadsForValidToken(): void
    {
        UserFactory::createOne([
            'email' => 'valid@example.com',
            'resetTokenHash' => hash('sha256', 'valid-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+1 hour'),
        ]);

        $crawler = $this->client->request('GET', '/reset-password/valid-token');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('form')->count());
    }

    public function testResetHappyPathChangesPasswordAndClearsToken(): void
    {
        $user = UserFactory::new()->legacy('somehash')->create([
            'email' => 'reset@example.com',
            'isVerified' => false,
            'resetTokenHash' => hash('sha256', 'happy-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+1 hour'),
        ]);
        $id = (string) $user->id;

        $crawler = $this->client->request('GET', '/reset-password/happy-token');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['password'] = 'brand-new-password';
        $form['password_repeat'] = 'brand-new-password';
        $this->client->submit($form);
        $this->assertResponseRedirects('/');

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($id);
        $this->assertNull($reloaded->resetTokenHash, 'token cleared after use');
        $this->assertNull($reloaded->resetTokenExpiresAt);
        $this->assertTrue($reloaded->isVerified, 'resetting via the e-mailed link verifies the account');
        $this->assertFalse($reloaded->legacyUser, 'a legacy account becomes a migrated account');
        $this->assertTrue($reloaded->legacyMigrated);

        // The new password validates.
        $hasher = static::getContainer()->get('security.user_password_hasher');
        $this->assertTrue($hasher->isPasswordValid($reloaded, 'brand-new-password'));
    }

    public function testResetWithMismatchedPasswordsReRendersForm(): void
    {
        UserFactory::createOne([
            'email' => 'mismatch@example.com',
            'resetTokenHash' => hash('sha256', 'mismatch-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+1 hour'),
        ]);

        $crawler = $this->client->request('GET', '/reset-password/mismatch-token');
        $form = $crawler->filter('form')->form();
        $form['password'] = 'password-one';
        $form['password_repeat'] = 'password-two';
        $this->client->submit($form);
        // Re-renders the form (200) rather than redirecting on success.
        $this->assertResponseIsSuccessful();
    }

    public function testResetWithShortPasswordReRendersForm(): void
    {
        UserFactory::createOne([
            'email' => 'short@example.com',
            'resetTokenHash' => hash('sha256', 'short-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+1 hour'),
        ]);

        $crawler = $this->client->request('GET', '/reset-password/short-token');
        $form = $crawler->filter('form')->form();
        $form['password'] = 'abc';
        $form['password_repeat'] = 'abc';
        $this->client->submit($form);
        $this->assertResponseIsSuccessful();
    }
}

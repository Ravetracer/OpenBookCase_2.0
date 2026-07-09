<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class RegistrationControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testRegisterPageLoads(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('form')->count());
    }

    public function testValidRegistrationCreatesUnverifiedUserAndSendsEmail(): void
    {
        $this->client->enableProfiler();

        $crawler = $this->client->request('GET', '/register');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['registration_form[username]'] = 'newcomer';
        $form['registration_form[email]'] = 'newcomer@example.com';
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        $form['registration_form[agreeTerms]']->tick();

        $this->client->submit($form);

        // Success path redirects to the homepage with a "check your inbox" flash.
        $this->assertResponseRedirects('/');

        // A verification email was queued.
        self::assertEmailCount(1);

        // The user exists and is NOT yet verified (the link must be clicked first).
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'newcomer@example.com']);
        $this->assertNotNull($user);
        $this->assertSame('newcomer', $user->username);
        $this->assertFalse($user->isVerified, 'new registrations stay unverified until the email link is clicked');
        $this->assertNotEmpty($user->password, 'the plain password should be hashed and stored');
    }

    public function testRegistrationWithoutAgreeTermsFails(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration_form[username]'] = 'noterms';
        $form['registration_form[email]'] = 'noterms@example.com';
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        // agreeTerms left unchecked → validation fails, no redirect.

        $this->client->submit($form);
        $this->assertResponseIsSuccessful(); // form re-rendered with errors (200, not a redirect)

        $this->assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'noterms@example.com']));
    }

    public function testRegistrationWithShortPasswordFails(): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration_form[username]'] = 'shortpw';
        $form['registration_form[email]'] = 'shortpw@example.com';
        $form['registration_form[plainPassword]'] = 'abc'; // < 6 chars
        $form['registration_form[agreeTerms]']->tick();

        $this->client->submit($form);
        $this->assertResponseIsSuccessful();
        $this->assertNull($this->em()->getRepository(User::class)->findOneBy(['email' => 'shortpw@example.com']));
    }

    /**
     * End-to-end: register, then click the actual signed link from the sent
     * email and assert the account becomes verified. This guards the whole
     * chain — in particular that the signed URL carries the `id` query
     * parameter the verify controller needs to resolve the user (its absence
     * previously left every new account permanently locked).
     */
    public function testClickingEmailedVerificationLinkVerifiesTheAccount(): void
    {
        $this->client->enableProfiler();

        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration_form[username]'] = 'clicker';
        $form['registration_form[email]'] = 'clicker@example.com';
        $form['registration_form[plainPassword]'] = 'sup3rsecret';
        $form['registration_form[agreeTerms]']->tick();
        $this->client->submit($form);
        $this->assertResponseRedirects('/');

        // Pull the signed verification URL out of the sent email.
        $email = $this->getMailerMessage();
        self::assertNotNull($email);
        $body = $email->getHtmlBody();
        self::assertIsString($body);
        self::assertMatchesRegularExpression('#href="([^"]*/verify/email\?[^"]+)"#', $body);
        preg_match('#href="([^"]*/verify/email\?[^"]+)"#', $body, $m);
        $signedUrl = html_entity_decode($m[1]);

        // The link must carry the user id, otherwise the controller can't
        // resolve the account and verification silently fails.
        self::assertStringContainsString('id=', $signedUrl, 'verification link must include the user id');

        // Not verified yet.
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'clicker@example.com']);
        $this->assertNotNull($user);
        $this->assertFalse($user->isVerified);

        // Click the link.
        $this->client->request('GET', $signedUrl);
        $this->assertResponseRedirects('/');

        // The account is now unlocked.
        $this->em()->clear();
        $user = $this->em()->getRepository(User::class)->findOneBy(['email' => 'clicker@example.com']);
        $this->assertTrue($user->isVerified, 'clicking the emailed link must verify the account');
    }

    /**
     * The /verify/email guard: an unknown/missing id redirects to the homepage
     * with a failure flash rather than 500ing.
     */
    public function testVerifyEmailWithUnknownIdRedirects(): void
    {
        $this->client->request('GET', '/verify/email');
        $this->assertResponseRedirects('/');
    }

    public function testVerifyEmailWithBogusUlidRedirects(): void
    {
        UserFactory::createOne(); // ensure a user exists, but not this id
        $this->client->request('GET', '/verify/email?id=01HZZZZZZZZZZZZZZZZZZZZZZZ');
        $this->assertResponseRedirects('/');
    }
}

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
     * The /verify/email link is a SymfonyCasts-signed URL whose signature can't be
     * forged in a test, so we only assert the route's guard: an unknown/missing id
     * redirects to the homepage with a failure flash rather than 500ing. The
     * successful click path is covered indirectly by the email being sent above.
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

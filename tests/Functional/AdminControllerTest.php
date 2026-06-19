<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiApplication;
use App\Entity\ApiUsageLog;
use App\Entity\Message;
use App\Entity\User;
use App\Enums\ApiApplicationStatus;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class AdminControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function loginAsAdmin(): void
    {
        $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
    }

    public function testAdminAreaRedirectsAnonymous(): void
    {
        $this->client->request('GET', '/admin');
        $this->assertResponseRedirects();
    }

    public function testAdminAreaForbiddenForNonAdmin(): void
    {
        $this->loginAsUser();
        $this->client->request('GET', '/admin');
        $this->assertResponseStatusCodeSame(403);
    }

    public function testAdminDashboardForAdmin(): void
    {
        $this->loginAsAdmin();
        $this->client->request('GET', '/admin');
        $this->assertResponseIsSuccessful();
    }

    public function testApplicationsListAndDetail(): void
    {
        $this->loginAsAdmin();
        ApiApplicationFactory::createOne(['appName' => 'Listed App']);

        $crawler = $this->client->request('GET', '/admin/api-applications');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Listed App', $crawler->html());

        $link = $crawler->filter('a[href*="/admin/api-applications/"]')->link();
        $this->client->click($link);
        $this->assertResponseIsSuccessful();
    }

    public function testApproveFlipsStatusAndNotifiesApplicant(): void
    {
        $this->loginAsAdmin();
        $applicant = UserFactory::createOne();
        $app = ApiApplicationFactory::createOne(['applicant' => $applicant]);

        $crawler = $this->client->request('GET', '/admin/api-applications/' . $app->id);
        $form = $crawler->filter('form[action$="/approve"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(ApiApplication::class)->find($app->id);
        $this->assertSame(ApiApplicationStatus::Approved, $reloaded->status);

        $messages = $this->em()->getRepository(Message::class)->findBy(['recipient' => $applicant->id]);
        $this->assertNotEmpty($messages);
    }

    public function testDenyRequiresReason(): void
    {
        $this->loginAsAdmin();
        $app = ApiApplicationFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/api-applications/' . $app->id);
        $form = $crawler->filter('form[action$="/deny"]')->form();
        $form['reason'] = ''; // empty reason must be rejected
        $this->client->submit($form);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(ApiApplication::class)->find($app->id);
        $this->assertSame(ApiApplicationStatus::Pending, $reloaded->status, 'deny without a reason must not change status');
    }

    public function testApiUsageViewListsAndFiltersLogs(): void
    {
        $this->loginAsAdmin();
        $app = ApiApplicationFactory::createOne(['appName' => 'Usage App']);

        $log = new ApiUsageLog();
        $log->apiApplication = $this->em()->getRepository(ApiApplication::class)->find($app->id);
        $log->oauthClientId = 'client_abc';
        $log->method = 'POST';
        $log->routeName = 'api_v1_bookcases_create';
        $log->path = '/api/v1/bookcases';
        $log->statusCode = 201;
        $log->requestPayload = ['title' => 'Payload Shelf'];
        $this->em()->persist($log);
        $this->em()->flush();

        $crawler = $this->client->request('GET', '/admin/api-usage');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('api_v1_bookcases_create', $crawler->html());
        $this->assertStringContainsString('Usage App', $crawler->html());
        $this->assertStringContainsString('Payload Shelf', $crawler->html());

        // Filtering by a different method hides the POST row.
        $crawler = $this->client->request('GET', '/admin/api-usage?method=DELETE');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString('api_v1_bookcases_create', $crawler->html());
    }

    public function testCheckBackSendsConversationMessage(): void
    {
        $this->loginAsAdmin();
        $applicant = UserFactory::createOne();
        $app = ApiApplicationFactory::createOne(['applicant' => $applicant]);

        $crawler = $this->client->request('GET', '/admin/api-applications/' . $app->id);
        $form = $crawler->filter('form[action$="/message"]')->form();
        $form['body'] = 'Can you describe your data retention?';
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $messages = $this->em()->getRepository(Message::class)->findBy(['recipient' => $applicant->id]);
        $this->assertCount(1, $messages);
        $this->assertNotNull($messages[0]->apiApplication);
    }

    // ── User management ──────────────────────────────────────────────────────

    /** Read a form's hidden CSRF token off the rendered detail page. */
    private function tokenFor(\Symfony\Component\DomCrawler\Crawler $crawler, string $actionSuffix): string
    {
        return $crawler->filter('form[action$="' . $actionSuffix . '"] input[name="_token"]')->attr('value');
    }

    public function testUsersListAndSearch(): void
    {
        $this->loginAsAdmin();
        UserFactory::createOne(['username' => 'alice_wonder', 'email' => 'alice@example.com']);
        UserFactory::createOne(['username' => 'bob_builder', 'email' => 'bob@example.com']);

        $crawler = $this->client->request('GET', '/admin/users');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('alice_wonder', $crawler->html());
        $this->assertStringContainsString('bob_builder', $crawler->html());

        // Search narrows the list.
        $crawler = $this->client->request('GET', '/admin/users?q=alice');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('alice_wonder', $crawler->html());
        $this->assertStringNotContainsString('bob_builder', $crawler->html());
    }

    public function testCorrectEmailUpdatesAndRequiresReverification(): void
    {
        $this->loginAsAdmin();
        $user = UserFactory::createOne(['email' => 'old@example.com', 'isVerified' => true]);

        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/email', [
            '_token' => $this->tokenFor($crawler, '/email'),
            'email' => 'fixed@example.com',
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($user->id);
        $this->assertSame('fixed@example.com', $reloaded->email);
        $this->assertFalse($reloaded->isVerified, 'a corrected address must be re-verified');
    }

    public function testAssignAndRevokeAdminRole(): void
    {
        $this->loginAsAdmin();
        $user = UserFactory::createOne(['roles' => []]);

        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/roles', [
            '_token' => $this->tokenFor($crawler, '/roles'),
            'roles' => ['ROLE_ADMIN'],
        ]);
        $this->assertResponseRedirects();

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($user->id);
        $this->assertContains('ROLE_ADMIN', $reloaded->roles);

        // Revoke again (empty submission).
        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/roles', [
            '_token' => $this->tokenFor($crawler, '/roles'),
        ]);
        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($user->id);
        $this->assertNotContains('ROLE_ADMIN', $reloaded->roles);
    }

    public function testSuspendAndUnsuspend(): void
    {
        $this->loginAsAdmin();
        $user = UserFactory::createOne(['isSuspended' => false]);

        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/suspend', [
            '_token' => $this->tokenFor($crawler, '/suspend'),
            'suspend' => '1',
        ]);
        $this->em()->clear();
        $this->assertTrue($this->em()->getRepository(User::class)->find($user->id)->isSuspended);

        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/suspend', [
            '_token' => $this->tokenFor($crawler, '/suspend'),
            'suspend' => '0',
        ]);
        $this->em()->clear();
        $this->assertFalse($this->em()->getRepository(User::class)->find($user->id)->isSuspended);
    }

    public function testResendVerificationSendsEmail(): void
    {
        $this->client->enableProfiler();
        $this->loginAsAdmin();
        $user = UserFactory::createOne(['isVerified' => false]);

        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/resend-verification', [
            '_token' => $this->tokenFor($crawler, '/resend-verification'),
        ]);
        $this->assertResponseRedirects();
        self::assertEmailCount(1);
    }

    public function testSendResetLinkStoresTokenAndSendsEmail(): void
    {
        $this->client->enableProfiler();
        $this->loginAsAdmin();
        $user = UserFactory::createOne();

        $crawler = $this->client->request('GET', '/admin/users/' . $user->id);
        $this->client->request('POST', '/admin/users/' . $user->id . '/reset-link', [
            '_token' => $this->tokenFor($crawler, '/reset-link'),
        ]);
        $this->assertResponseRedirects();
        self::assertEmailCount(1);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($user->id);
        $this->assertNotNull($reloaded->resetTokenHash);
        $this->assertNotNull($reloaded->resetTokenExpiresAt);
    }

    public function testDeleteUserRemovesAccount(): void
    {
        $this->loginAsAdmin();
        $user = UserFactory::createOne();
        $userId = $user->id;

        $crawler = $this->client->request('GET', '/admin/users/' . $userId);
        $this->client->request('POST', '/admin/users/' . $userId . '/delete', [
            '_token' => $this->tokenFor($crawler, '/delete'),
        ]);
        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $this->assertNull($this->em()->getRepository(User::class)->find($userId));
    }

    public function testAdminCannotDeleteOwnAccount(): void
    {
        $admin = $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);

        $crawler = $this->client->request('GET', '/admin/users/' . $admin->id);
        $this->client->request('POST', '/admin/users/' . $admin->id . '/delete', [
            '_token' => $this->tokenFor($crawler, '/delete'),
        ]);
        // Stays on the detail page, account still present.
        $this->assertResponseRedirects('/admin/users/' . $admin->id);

        $this->em()->clear();
        $this->assertNotNull($this->em()->getRepository(User::class)->find($admin->id));
    }

    public function testAdminCannotRemoveOwnAdminRole(): void
    {
        $admin = $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);

        $crawler = $this->client->request('GET', '/admin/users/' . $admin->id);
        $this->client->request('POST', '/admin/users/' . $admin->id . '/roles', [
            '_token' => $this->tokenFor($crawler, '/roles'),
            'roles' => [],
        ]);

        $this->em()->clear();
        $reloaded = $this->em()->getRepository(User::class)->find($admin->id);
        $this->assertContains('ROLE_ADMIN', $reloaded->roles, 'self-demotion must be blocked');
    }
}

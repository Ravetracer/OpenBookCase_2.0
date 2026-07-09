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

    // ── Bulk user operations ─────────────────────────────────────────────────

    /** Read the bulk form's CSRF token off the rendered users list. */
    private function bulkToken(): string
    {
        $crawler = $this->client->request('GET', '/admin/users');

        return $this->tokenFor($crawler, '/users/bulk');
    }

    public function testBulkResendSendsEmailsWithOptionalReason(): void
    {
        $this->client->enableProfiler();
        $this->loginAsAdmin();
        $a = UserFactory::createOne(['isVerified' => false]);
        $b = UserFactory::createOne(['isVerified' => false]);

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'resend',
            'reason' => 'A bug prevented earlier links from working. Here is a fresh one.',
            'ids' => [(string) $a->id, (string) $b->id],
        ]);
        $this->assertResponseRedirects('/admin/users');

        self::assertEmailCount(2);
        // The admin reason is rendered into the verification e-mail.
        $body = $this->getMailerMessage(0)->getHtmlBody();
        self::assertStringContainsString('A bug prevented earlier links from working', (string) $body);
    }

    public function testBulkResendWithoutReasonOmitsTheNote(): void
    {
        $this->client->enableProfiler();
        $this->loginAsAdmin();
        $a = UserFactory::createOne(['isVerified' => false]);

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'resend',
            'ids' => [(string) $a->id],
        ]);
        $this->assertResponseRedirects('/admin/users');
        self::assertEmailCount(1);
        // No reason intro block when no reason was supplied.
        $body = (string) $this->getMailerMessage(0)->getHtmlBody();
        self::assertStringNotContainsString('Note from the OpenBookCase team', $body);
    }

    public function testBulkSuspendAndUnsuspend(): void
    {
        $this->loginAsAdmin();
        $a = UserFactory::createOne(['isSuspended' => false]);
        $b = UserFactory::createOne(['isSuspended' => false]);

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'suspend',
            'ids' => [(string) $a->id, (string) $b->id],
        ]);
        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $repo = $this->em()->getRepository(User::class);
        $this->assertTrue($repo->find($a->id)->isSuspended);
        $this->assertTrue($repo->find($b->id)->isSuspended);

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'unsuspend',
            'ids' => [(string) $a->id, (string) $b->id],
        ]);
        $this->em()->clear();
        $this->assertFalse($repo->find($a->id)->isSuspended);
        $this->assertFalse($repo->find($b->id)->isSuspended);
    }

    public function testBulkDeleteRemovesSelectedAccounts(): void
    {
        $this->loginAsAdmin();
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        $keep = UserFactory::createOne();
        [$aId, $bId, $keepId] = [$a->id, $b->id, $keep->id];

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'delete',
            'ids' => [(string) $aId, (string) $bId],
        ]);
        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $repo = $this->em()->getRepository(User::class);
        $this->assertNull($repo->find($aId));
        $this->assertNull($repo->find($bId));
        $this->assertNotNull($repo->find($keepId), 'unselected accounts are untouched');
    }

    public function testBulkDeleteSkipsOwnAccount(): void
    {
        $admin = $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $other = UserFactory::createOne();
        [$adminId, $otherId] = [$admin->id, $other->id];

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'delete',
            'ids' => [(string) $adminId, (string) $otherId],
        ]);
        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $repo = $this->em()->getRepository(User::class);
        $this->assertNotNull($repo->find($adminId), 'admin must not delete their own account in bulk');
        $this->assertNull($repo->find($otherId));
    }

    public function testBulkSuspendSkipsOwnAccount(): void
    {
        $admin = $this->loginAsUser(['roles' => ['ROLE_ADMIN']]);
        $adminId = $admin->id;

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => $this->bulkToken(),
            'action' => 'suspend',
            'ids' => [(string) $adminId],
        ]);

        $this->em()->clear();
        $this->assertFalse($this->em()->getRepository(User::class)->find($adminId)->isSuspended, 'admin must not suspend themselves in bulk');
    }

    public function testBulkRejectsInvalidCsrfToken(): void
    {
        $this->loginAsAdmin();
        $a = UserFactory::createOne(['isSuspended' => false]);

        $this->client->request('POST', '/admin/users/bulk', [
            '_token' => 'bogus',
            'action' => 'suspend',
            'ids' => [(string) $a->id],
        ]);
        $this->assertResponseRedirects('/admin/users');

        $this->em()->clear();
        $this->assertFalse($this->em()->getRepository(User::class)->find($a->id)->isSuspended);
    }

    public function testBulkRequiresAdmin(): void
    {
        $this->loginAsUser(); // plain ROLE_USER
        $this->client->request('POST', '/admin/users/bulk', [
            'action' => 'delete',
            'ids' => [],
        ]);
        $this->assertResponseStatusCodeSame(403);
    }
}

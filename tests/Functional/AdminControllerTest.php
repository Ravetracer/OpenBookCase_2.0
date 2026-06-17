<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\ApiApplication;
use App\Entity\ApiUsageLog;
use App\Entity\Message;
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
}

<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Message;
use App\Enums\ApiApplicationStatus;
use App\Enums\ApiClientType;
use App\Enums\MessageType;
use App\Repository\MessageRepository;
use App\Service\ApiApplicationService;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiApplicationServiceTest extends KernelTestCase
{
    private function service(): ApiApplicationService
    {
        return self::getContainer()->get(ApiApplicationService::class);
    }

    private function messages(): MessageRepository
    {
        return self::getContainer()->get(MessageRepository::class);
    }

    /** Messages stored for one recipient (channel defaults to Internal). */
    private function inbox(\App\Entity\User $user): array
    {
        return $this->messages()->findBy(['recipient' => $user->id]);
    }

    public function testApplyCreatesPendingAndNotifiesAdmins(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $applicant = UserFactory::createOne();

        $application = $this->service()->apply(
            $applicant,
            'My App',
            'A detailed, sufficiently long use case description.',
            ApiClientType::PublicClient,
            ['https://example.com/cb'],
            ['bookcases.write'],
        );

        $this->assertSame(ApiApplicationStatus::Pending, $application->status);
        $this->assertSame($applicant->id, $application->applicant->id);

        $adminInbox = $this->inbox($admin);
        $this->assertCount(1, $adminInbox);
        $this->assertSame(MessageType::ApiAccess, $adminInbox[0]->type);
        // The initial admin ping is informational — not part of the reply thread.
        $this->assertNull($adminInbox[0]->apiApplication);
    }

    public function testApproveSetsStatusAndNotifiesApplicantInThread(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $application = ApiApplicationFactory::createOne();
        $applicant = $application->applicant;

        $this->service()->approve($application, $admin);

        $this->assertSame(ApiApplicationStatus::Approved, $application->status);
        $this->assertSame($admin->id, $application->decidedBy->id);
        $this->assertNotNull($application->decidedAt);

        /** @var Message $msg */
        $msg = $this->inbox($applicant)[0];
        $this->assertSame($admin->id, $msg->sender->id);
        $this->assertNotNull($msg->apiApplication);
        $this->assertSame((string) $application->id, (string) $msg->apiApplication->id);
    }

    public function testDenyStoresReasonAndNotifies(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $application = ApiApplicationFactory::createOne();

        $this->service()->deny($application, $admin, 'Use case too vague.');

        $this->assertSame(ApiApplicationStatus::Denied, $application->status);
        $this->assertSame('Use case too vague.', $application->decisionReason);
        $this->assertNotEmpty($this->inbox($application->applicant));
    }

    public function testPostMessageRoutesByDirection(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $application = ApiApplicationFactory::createOne();
        $applicant = $application->applicant;

        // Admin "checks back" → reaches the applicant.
        $this->service()->postMessage($application, $admin, 'Could you clarify the data flow?');
        $applicantInbox = $this->inbox($applicant);
        $this->assertCount(1, $applicantInbox);
        $this->assertSame($admin->id, $applicantInbox[0]->sender->id);

        // Applicant replies → routed back to the admin who last wrote.
        $this->service()->postMessage($application, $applicant, 'Sure — it is read-only.');
        $adminInbox = $this->inbox($admin);
        $this->assertCount(1, $adminInbox);
        $this->assertSame($applicant->id, $adminInbox[0]->sender->id);
        $this->assertSame((string) $application->id, (string) $adminInbox[0]->apiApplication->id);
    }

    public function testRevokeFromApproved(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $application = ApiApplicationFactory::new()->approved()->create();

        $this->service()->revoke($application, $admin, 'Terms violation.');

        $this->assertSame(ApiApplicationStatus::Revoked, $application->status);
        $this->assertSame('Terms violation.', $application->decisionReason);
    }
}

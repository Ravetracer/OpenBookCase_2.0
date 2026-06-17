<?php declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\ApiApplication;
use App\Enums\ApiApplicationStatus;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class ApiApplicationControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    /** Read a CSRF token straight out of the profile modal on the homepage. */
    private function csrf(string $selector): string
    {
        $crawler = $this->client->request('GET', '/');
        $this->assertResponseIsSuccessful();
        $node = $crawler->filter($selector);
        $this->assertGreaterThan(0, $node->count(), "token input not found: $selector");

        return $node->attr('value');
    }

    private function applyForm(): array
    {
        return [
            'appName' => 'My Reader App',
            'useCase' => 'A mobile app that shows nearby bookcases to my users.',
            'clientType' => 'public',
            'scopes' => ['bookcases.write', 'wishlist.write'],
            'redirectUris' => "myapp://callback\nhttps://example.com/cb",
        ];
    }

    public function testApplyCreatesPendingApplication(): void
    {
        UserFactory::new()->admin()->create();
        $user = $this->loginAsUser();

        $token = $this->csrf('#profileModal form[data-action*="applyApi"] input[name="_token"]');
        $this->client->request('POST', '/profile/api/apply', ['_token' => $token] + $this->applyForm());

        $this->assertResponseStatusCodeSame(201);

        $app = $this->em()->getRepository(ApiApplication::class)->findOneBy([]);
        $this->assertNotNull($app);
        $this->assertSame(ApiApplicationStatus::Pending, $app->status);
        $this->assertSame((string) $user->id, (string) $app->applicant->id);
        $this->assertSame(['bookcases.write', 'wishlist.write'], $app->requestedScopes);
        $this->assertContains('myapp://callback', $app->redirectUris);
    }

    public function testApplyRejectsInvalidCsrf(): void
    {
        $this->loginAsUser();
        $this->client->request('POST', '/profile/api/apply', ['_token' => 'bogus'] + $this->applyForm());
        $this->assertResponseStatusCodeSame(400);
    }

    public function testApplyConflictsWhenAlreadyPending(): void
    {
        UserFactory::new()->admin()->create();
        $this->loginAsUser();
        $token = $this->csrf('#profileModal form[data-action*="applyApi"] input[name="_token"]');

        // First application succeeds; a second (same session/token) is a conflict.
        $this->client->request('POST', '/profile/api/apply', ['_token' => $token] + $this->applyForm());
        $this->assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/profile/api/apply', ['_token' => $token] + $this->applyForm());
        $this->assertResponseStatusCodeSame(409);
    }

    public function testApplicantCanReplyInThread(): void
    {
        UserFactory::new()->admin()->create();
        $user = $this->loginAsUser();
        ApiApplicationFactory::createOne(['applicant' => $user]);

        // Pending state renders the reply form in the profile modal.
        $token = $this->csrf('#profileModal form[data-action*="replyApi"] input[name="_token"]');
        $app = $this->em()->getRepository(ApiApplication::class)->findOneBy([]);

        $this->client->request('POST', '/profile/api/' . $app->id . '/reply', [
            '_token' => $token,
            'body' => 'Happy to clarify anything.',
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertSame('success', $this->json()['status']);
    }

    public function testReplyForbiddenForNonOwner(): void
    {
        $owner = UserFactory::createOne();
        $foreignApp = ApiApplicationFactory::createOne(['applicant' => $owner]);

        // A different user — give them their own pending app so the reply form (and
        // thus a valid 'api_reply' token) is rendered, then post to the foreign app.
        $intruder = $this->loginAsUser();
        ApiApplicationFactory::createOne(['applicant' => $intruder]);
        $token = $this->csrf('#profileModal form[data-action*="replyApi"] input[name="_token"]');

        $this->client->request('POST', '/profile/api/' . $foreignApp->id . '/reply', [
            '_token' => $token,
            'body' => 'Let me in.',
        ]);
        $this->assertResponseStatusCodeSame(403);
    }
}

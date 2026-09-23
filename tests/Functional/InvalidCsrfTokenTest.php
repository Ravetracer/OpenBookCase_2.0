<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\User;
use App\Tests\Factory\UserFactory;

/**
 * A stale/forged token on an action guarded by #[IsCsrfTokenValid] must not bounce a
 * logged-in user to the login page: they go back where they came from, with the
 * "invalid token" flash, and nothing is changed.
 */
final class InvalidCsrfTokenTest extends FunctionalTestCase
{
    public function testInvalidTokenRedirectsBackWithFlashAndChangesNothing(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $this->client->loginUser($admin);

        $detail = 'http://localhost/admin/users/' . $target->id;
        $this->client->request('POST', '/admin/users/' . $target->id . '/suspend', [
            'suspend' => '1',
            '_token' => 'forged',
        ], [], ['HTTP_REFERER' => $detail]);

        $this->assertResponseRedirects($detail);
        $this->client->followRedirect();
        $this->assertSelectorTextContains('body', 'Invalid security token');

        $fresh = static::getContainer()->get('doctrine')->getManager()->getRepository(User::class)->find($target->id);
        $this->assertFalse($fresh->isSuspended);
    }

    public function testForeignRefererIsNotFollowed(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $target = UserFactory::createOne();
        $this->client->loginUser($admin);

        $this->client->request('POST', '/admin/users/' . $target->id . '/suspend', [
            'suspend' => '1',
            '_token' => 'forged',
        ], [], ['HTTP_REFERER' => 'https://evil.example/phish']);

        $this->assertResponseRedirects('/');
    }
}

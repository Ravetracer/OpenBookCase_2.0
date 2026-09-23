<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\User;
use App\Service\UserAdminService;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Model\AccessToken;
use League\Bundle\OAuth2ServerBundle\Model\Client;
use League\Bundle\OAuth2ServerBundle\Model\RefreshToken;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class UserAdminServiceTest extends KernelTestCase
{
    public function testSuspendingRevokesOAuthCredentials(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        [$accessId, $refreshId] = $this->issueTokens($user);

        $this->assertTrue($this->service()->setSuspended($user, true, $admin));

        $this->em()->clear();
        $this->assertTrue($this->em()->find(User::class, $user->id)->isSuspended);
        $this->assertTrue($this->em()->find(AccessToken::class, $accessId)->isRevoked(), 'access token must be revoked');
        $this->assertTrue($this->em()->find(RefreshToken::class, $refreshId)->isRevoked(), 'refresh token must be revoked');
    }

    public function testUnsuspendingLeavesTokensAlone(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();
        [$accessId] = $this->issueTokens($user);

        $this->assertTrue($this->service()->setSuspended($user, false, $admin));

        $this->em()->clear();
        $this->assertFalse($this->em()->find(AccessToken::class, $accessId)->isRevoked());
    }

    public function testAdminCannotSuspendThemselves(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->assertFalse($this->service()->setSuspended($admin, true, $admin));
        $this->assertFalse($admin->isSuspended);
    }

    public function testBulkSuspendSkipsSelfAndRevokesOthers(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $a = UserFactory::createOne();
        $b = UserFactory::createOne();
        [$accessId] = $this->issueTokens($a);

        $result = $this->service()->bulk(UserAdminService::BULK_SUSPEND, [$a, $b, $admin], $admin);

        $this->assertSame(['count' => 2, 'skippedSelf' => true], $result);
        $this->em()->clear();
        $this->assertTrue($this->em()->find(User::class, $a->id)->isSuspended);
        $this->assertTrue($this->em()->find(User::class, $b->id)->isSuspended);
        $this->assertFalse($this->em()->find(User::class, $admin->id)->isSuspended);
        $this->assertTrue($this->em()->find(AccessToken::class, $accessId)->isRevoked());
    }

    public function testBulkDeleteSkipsSelf(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $victim = UserFactory::createOne();
        $adminId = $admin->id;
        $victimId = $victim->id;

        $result = $this->service()->bulk(UserAdminService::BULK_DELETE, [$victim, $admin], $admin);

        $this->assertSame(['count' => 1, 'skippedSelf' => true], $result);
        $this->em()->clear();
        $this->assertNull($this->em()->find(User::class, $victimId));
        $this->assertNotNull($this->em()->find(User::class, $adminId));
    }

    public function testBulkResendMailsEveryUser(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $result = $this->service()->bulk(UserAdminService::BULK_RESEND, UserFactory::createMany(2), $admin, 'Please verify');

        $this->assertSame(['count' => 2, 'skippedSelf' => false], $result);
        self::assertEmailCount(2);
    }

    public function testBulkUnknownActionReturnsNull(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->assertNull($this->service()->bulk('explode', [UserFactory::createOne()], $admin));
    }

    public function testFindUsersDropsInvalidAndUnknownIds(): void
    {
        $user = UserFactory::createOne();

        $found = $this->service()->findUsers([(string) $user->id, 'not-a-ulid', '01ARZ3NDEKTSV4RRFFQ69G5FAV', ['nested'], 42]);

        $this->assertCount(1, $found);
        $this->assertSame((string) $user->id, (string) $found[0]->id);
    }

    public function testUpdateRolesKeepsOnlyAssignableRoles(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();

        $this->assertTrue($this->service()->updateRoles($user, ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $admin));
        $this->assertSame(['ROLE_ADMIN'], $user->roles);
    }

    public function testAdminCannotDemoteThemselves(): void
    {
        $admin = UserFactory::new()->admin()->create();

        $this->assertFalse($this->service()->updateRoles($admin, [], $admin));
        $this->assertSame(['ROLE_ADMIN'], $admin->roles);
    }

    private function service(): UserAdminService
    {
        return self::getContainer()->get(UserAdminService::class);
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    /** @return array{string, string} access and refresh token identifiers */
    private function issueTokens(User $user): array
    {
        $client = new Client('Test client', bin2hex(random_bytes(8)), null);
        $access = new AccessToken(bin2hex(random_bytes(16)), new \DateTimeImmutable('+1 hour'), $client, $user->getUserIdentifier(), []);
        $refresh = new RefreshToken(bin2hex(random_bytes(16)), new \DateTimeImmutable('+1 year'), $access);

        foreach ([$client, $access, $refresh] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return [$access->getIdentifier(), $refresh->getIdentifier()];
    }
}

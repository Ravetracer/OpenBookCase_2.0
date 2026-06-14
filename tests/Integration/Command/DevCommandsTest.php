<?php declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Repository\BookcaseRepository;
use App\Repository\UserRepository;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke tests for the developer helper commands.
 *
 * app:dev:db-init is intentionally NOT tested: it drops and recreates the whole
 * schema (doctrine:database:drop + schema:create), which would destroy the test
 * database the harness relies on. app:dev:fixtures is tested WITHOUT --fresh for
 * the same reason (--fresh delegates to app:dev:db-init).
 */
final class DevCommandsTest extends KernelTestCase
{
    private function tester(string $name): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find($name));
    }

    public function testCreateUserCreatesAVerifiedUser(): void
    {
        self::bootKernel();

        $tester = $this->tester('app:dev:create-user');
        $tester->execute([
            'username' => 'devsmoke',
            'email' => 'DevSmoke@Example.com',
            'password' => 'secret123',
        ]);
        $tester->assertCommandIsSuccessful();

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'devsmoke']);
        $this->assertNotNull($user);
        $this->assertSame('devsmoke@example.com', $user->email, 'email is lower-cased');
        $this->assertTrue($user->isVerified);
        $this->assertNotSame('', $user->getPassword());
        $this->assertNotContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testCreateUserWithAdminGrantsRole(): void
    {
        self::bootKernel();

        $tester = $this->tester('app:dev:create-user');
        $tester->execute([
            'username' => 'adminsmoke',
            'email' => 'adminsmoke@example.com',
            'password' => 'secret123',
            '--admin' => true,
        ]);
        $tester->assertCommandIsSuccessful();

        $user = self::getContainer()->get(UserRepository::class)->findOneBy(['username' => 'adminsmoke']);
        $this->assertNotNull($user);
        $this->assertContains('ROLE_ADMIN', $user->getRoles());
    }

    public function testCreateUserRejectsDuplicateUsername(): void
    {
        self::bootKernel();
        UserFactory::createOne(['username' => 'taken', 'email' => 'taken@example.com']);

        $tester = $this->tester('app:dev:create-user');
        $exit = $tester->execute([
            'username' => 'taken',
            'email' => 'other@example.com',
            'password' => 'secret123',
        ]);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function testFixturesSeedsUsersAndBookcases(): void
    {
        self::bootKernel();

        $tester = $this->tester('app:dev:fixtures');
        // No --fresh (would drop the schema); no extra random pins for speed.
        $tester->execute(['--count' => '0']);
        $tester->assertCommandIsSuccessful();

        $users = self::getContainer()->get(UserRepository::class);
        $bookcases = self::getContainer()->get(BookcaseRepository::class);

        // The fixtures create the canonical dev/admin logins + curated bookcases.
        $this->assertNotNull($users->findOneBy(['email' => 'dev@example.com']));
        $this->assertNotNull($users->findOneBy(['email' => 'admin@example.com']));
        $this->assertGreaterThanOrEqual(10, $bookcases->count([]), 'curated bookcases were created');
        $this->assertStringContainsString('Seeded', $tester->getDisplay());
    }
}

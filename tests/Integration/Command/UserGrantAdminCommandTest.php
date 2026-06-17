<?php declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Entity\User;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class UserGrantAdminCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester($application->find('app:user:grant-admin'));
    }

    /** @return string[] the stored roles of the user with this id (fresh from the DB) */
    private function storedRoles(string $id): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(User::class)->find($id)->roles;
    }

    public function testGrantsAdmin(): void
    {
        $user = UserFactory::createOne(['email' => 'grant@example.com', 'roles' => []]);

        $tester = $this->tester();
        $this->assertSame(Command::SUCCESS, $tester->execute(['email' => 'grant@example.com']));
        $this->assertContains('ROLE_ADMIN', $this->storedRoles((string) $user->id));
    }

    public function testEmailLookupIsCaseInsensitive(): void
    {
        $user = UserFactory::createOne(['email' => 'Mixed@Case.com', 'roles' => []]);

        $this->assertSame(Command::SUCCESS, $this->tester()->execute(['email' => 'mixed@case.com']));
        $this->assertContains('ROLE_ADMIN', $this->storedRoles((string) $user->id));
    }

    public function testRevokeRemovesAdmin(): void
    {
        $user = UserFactory::createOne(['email' => 'revoke@example.com', 'roles' => ['ROLE_ADMIN']]);

        $this->assertSame(Command::SUCCESS, $this->tester()->execute(['email' => 'revoke@example.com', '--revoke' => true]));
        $this->assertNotContains('ROLE_ADMIN', $this->storedRoles((string) $user->id));
    }

    public function testUnknownEmailFails(): void
    {
        $tester = $this->tester();
        $this->assertSame(Command::FAILURE, $tester->execute(['email' => 'nobody@example.com']));
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Integration\Security;

use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class UserCheckerTest extends TestCase
{
    public function testUnverifiedUserIsBlocked(): void
    {
        $user = new User();
        $user->isVerified = false;

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('account.not_verified');

        (new UserChecker())->checkPreAuth($user);
    }

    public function testVerifiedUserPasses(): void
    {
        $user = new User();
        $user->isVerified = true;

        (new UserChecker())->checkPreAuth($user);
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function testSuspendedUserIsBlocked(): void
    {
        $user = new User();
        $user->isVerified = true;
        $user->isSuspended = true;

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('account.suspended');

        (new UserChecker())->checkPreAuth($user);
    }

    public function testNonAppUserIsIgnored(): void
    {
        // Foreign user types must not trip the verified check.
        (new UserChecker())->checkPreAuth(new InMemoryUser('x', 'y'));
        $this->addToAssertionCount(1);
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\PasswordResetService;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PasswordResetServiceTest extends KernelTestCase
{
    /** @return iterable<string, array{string, string, ?string}> */
    public static function passwords(): iterable
    {
        yield 'acceptable' => ['correct horse', 'correct horse', null];
        yield 'too short' => ['short', 'short', 'reset.password_too_short'];
        yield 'mismatch' => ['correct horse', 'correct horsE', 'reset.password_mismatch'];
    }

    #[DataProvider('passwords')]
    public function testPasswordError(string $password, string $repeat, ?string $expected): void
    {
        $this->assertSame($expected, $this->service()->passwordError($password, $repeat));
    }

    public function testFindUserByTokenHonoursHashAndExpiry(): void
    {
        $valid = UserFactory::createOne([
            'resetTokenHash' => hash('sha256', 'valid-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+30 minutes'),
        ]);
        UserFactory::createOne([
            'resetTokenHash' => hash('sha256', 'expired-token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('-1 minute'),
        ]);

        $this->assertSame((string) $valid->id, (string) $this->service()->findUserByToken('valid-token')?->id);
        $this->assertNull($this->service()->findUserByToken('expired-token'));
        $this->assertNull($this->service()->findUserByToken('unknown-token'));
    }

    public function testResetPasswordClearsTokenAndMigratesLegacyAccount(): void
    {
        $user = UserFactory::createOne([
            'resetTokenHash' => hash('sha256', 'token'),
            'resetTokenExpiresAt' => new \DateTimeImmutable('+30 minutes'),
            'isVerified' => false,
            'legacyUser' => true,
        ]);

        $this->service()->resetPassword($user, 'a brand new password');

        $this->assertNull($user->resetTokenHash);
        $this->assertNull($user->resetTokenExpiresAt);
        $this->assertTrue($user->isVerified);
        $this->assertFalse($user->legacyUser);
        $this->assertTrue($user->legacyMigrated);
    }

    public function testSendResetLinkStoresTokenAndMails(): void
    {
        $user = UserFactory::createOne();

        $this->service()->sendResetLink($user);

        $this->assertNotNull($user->resetTokenHash);
        $this->assertGreaterThan(new \DateTimeImmutable(), $user->resetTokenExpiresAt);
        self::assertEmailCount(1);
    }

    private function service(): PasswordResetService
    {
        return self::getContainer()->get(PasswordResetService::class);
    }
}

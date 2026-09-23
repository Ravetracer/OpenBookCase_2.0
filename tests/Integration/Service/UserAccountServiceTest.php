<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\UserAccountService;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class UserAccountServiceTest extends KernelTestCase
{
    public function testOwnEmailChangeRequiresReverificationAndMails(): void
    {
        $user = UserFactory::createOne(['email' => 'old@example.com', 'isVerified' => true]);

        $this->assertSame(UserAccountService::EMAIL_CHANGED, $this->service()->changeOwnEmail($user, 'new@example.com'));
        $this->assertSame('new@example.com', $user->email);
        $this->assertFalse($user->isVerified);
        self::assertEmailCount(1);
    }

    public function testOwnEmailOutcomes(): void
    {
        UserFactory::createOne(['email' => 'taken@example.com']);
        $user = UserFactory::createOne(['email' => 'mine@example.com', 'isVerified' => true]);

        $this->assertSame(UserAccountService::EMAIL_INVALID, $this->service()->changeOwnEmail($user, 'not-an-email'));
        $this->assertSame(UserAccountService::EMAIL_UNCHANGED, $this->service()->changeOwnEmail($user, 'MINE@example.com'));
        $this->assertSame(UserAccountService::EMAIL_TAKEN, $this->service()->changeOwnEmail($user, 'taken@example.com'));
        $this->assertTrue($user->isVerified);
        self::assertEmailCount(0);
    }

    public function testAdminEmailCorrectionSendsNoMail(): void
    {
        $user = UserFactory::createOne(['email' => 'typo@exmaple.com', 'isVerified' => true]);

        $this->assertSame(UserAccountService::EMAIL_CHANGED, $this->service()->changeEmailByAdmin($user, 'fixed@example.com'));
        $this->assertFalse($user->isVerified);
        self::assertEmailCount(0);
    }

    public function testUpdateHomeRejectsOutOfRangeCoordinates(): void
    {
        $user = UserFactory::createOne();

        $this->assertFalse($this->service()->updateHome($user, new InputBag(['enabled' => '1', 'latitude' => '91', 'longitude' => '0'])));
        $this->assertNull($user->homeLatitude);
    }

    public function testUpdateHomeClampsZoomAndClearHomeResets(): void
    {
        $user = UserFactory::createOne();

        $this->assertTrue($this->service()->updateHome($user, new InputBag(['enabled' => '1', 'latitude' => '48.1', 'longitude' => '11.5', 'zoom' => '42'])));
        $this->assertSame(19, $user->homeZoom);
        $this->assertTrue($user->useHomeLocation);

        $this->service()->clearHome($user);
        $this->assertNull($user->homeLatitude);
        $this->assertFalse($user->useHomeLocation);
    }

    private function service(): UserAccountService
    {
        return self::getContainer()->get(UserAccountService::class);
    }
}

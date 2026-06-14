<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\Message;
use App\Enums\MessageType;
use App\Enums\NotificationChannel;
use App\Repository\MessageRepository;
use App\Service\MessageService;
use App\Tests\Factory\BookcaseFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * MessageService is constructed with a mock mailer so we can assert exactly how
 * many e-mails each NotificationChannel triggers, while the inbox side effect is
 * verified against the real (test) database.
 */
final class MessageServiceTest extends KernelTestCase
{
    private function service(MailerInterface $mailer): MessageService
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        return new MessageService($em, $mailer);
    }

    private function messageCount(): int
    {
        return self::getContainer()->get(MessageRepository::class)->count([]);
    }

    public function testInternalChannelStoresMessageAndSendsNoEmail(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $user = UserFactory::createOne(['notificationChannel' => NotificationChannel::Internal]);

        $message = $this->service($mailer)->notify($user, 'Hello', MessageType::Update, 'Subj');

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame('Hello', $message->body);
        $this->assertSame('Subj', $message->subject);
        $this->assertSame(1, $this->messageCount());
    }

    public function testEmailChannelSendsEmailAndStoresNothing(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send')->with($this->isInstanceOf(Email::class));

        $user = UserFactory::createOne([
            'notificationChannel' => NotificationChannel::Email,
            'isVerified' => true,
        ]);

        $result = $this->service($mailer)->notify($user, 'Body');

        $this->assertNull($result, 'e-mail-only delivery stores no inbox message');
        $this->assertSame(0, $this->messageCount());
    }

    public function testBothChannelSendsEmailAndStoresMessage(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->once())->method('send');

        $user = UserFactory::createOne([
            'notificationChannel' => NotificationChannel::Both,
            'isVerified' => true,
        ]);

        $message = $this->service($mailer)->notify($user, 'Body');

        $this->assertInstanceOf(Message::class, $message);
        $this->assertSame(1, $this->messageCount());
    }

    public function testEmailChannelFallsBackToInboxWhenAddressUnusable(): void
    {
        // Unverified address can't be e-mailed → must not silently drop the notice.
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects($this->never())->method('send');

        $user = UserFactory::createOne([
            'notificationChannel' => NotificationChannel::Email,
            'isVerified' => false,
        ]);

        $message = $this->service($mailer)->notify($user, 'Body');

        $this->assertInstanceOf(Message::class, $message, 'fallback stores the message internally');
        $this->assertSame(1, $this->messageCount());
    }

    public function testNotifyManyDeliversToEachRecipient(): void
    {
        $mailer = $this->createMock(MailerInterface::class);
        $users = UserFactory::createMany(3, ['notificationChannel' => NotificationChannel::Internal]);
        $bookcase = BookcaseFactory::createOne();

        $this->service($mailer)->notifyMany(
            $users,
            'Broadcast',
            MessageType::BookcaseChanged,
            'Changed',
            $bookcase,
        );

        $this->assertSame(3, $this->messageCount());
    }
}

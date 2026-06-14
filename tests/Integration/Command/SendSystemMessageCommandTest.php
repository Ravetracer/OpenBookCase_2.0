<?php declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Enums\MessageType;
use App\Enums\NotificationChannel;
use App\Repository\MessageRepository;
use App\Tests\Factory\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SendSystemMessageCommandTest extends KernelTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:message:send'));
    }

    private function messageCount(): int
    {
        return self::getContainer()->get(MessageRepository::class)->count([]);
    }

    public function testSendsToOneUserStoresInboxMessage(): void
    {
        self::bootKernel();
        $user = UserFactory::createOne([
            'username' => 'alice',
            'notificationChannel' => NotificationChannel::Internal,
        ]);

        $tester = $this->tester();
        $tester->execute([
            'username' => 'alice',
            'body' => 'Hello there',
            '--subject' => 'Greetings',
        ]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(1, $this->messageCount());

        $message = self::getContainer()->get(MessageRepository::class)->findAll()[0];
        $this->assertSame('Hello there', $message->body);
        $this->assertSame('Greetings', $message->subject);
        $this->assertSame((string) $user->id, (string) $message->recipient->id);
        $this->assertStringContainsString('Sent', $tester->getDisplay());
    }

    public function testTypeOptionIsHonoured(): void
    {
        self::bootKernel();
        UserFactory::createOne(['username' => 'bob', 'notificationChannel' => NotificationChannel::Internal]);

        $tester = $this->tester();
        $tester->execute([
            'username' => 'bob',
            'body' => 'A change happened',
            '--type' => MessageType::BookcaseChanged->value,
        ]);

        $tester->assertCommandIsSuccessful();
        $message = self::getContainer()->get(MessageRepository::class)->findAll()[0];
        $this->assertSame(MessageType::BookcaseChanged, $message->type);
    }

    public function testAllSendsToEveryUser(): void
    {
        self::bootKernel();
        UserFactory::createMany(3, ['notificationChannel' => NotificationChannel::Internal]);

        $tester = $this->tester();
        $tester->execute(['body' => 'Broadcast to all', '--all' => true]);

        $tester->assertCommandIsSuccessful();
        $this->assertSame(3, $this->messageCount());
        $this->assertStringContainsString('to 3 user(s)', $tester->getDisplay());
    }

    public function testUnknownUserFails(): void
    {
        self::bootKernel();

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'ghost', 'body' => 'Hi']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertSame(0, $this->messageCount());
        $this->assertStringContainsString('No user with username', $tester->getDisplay());
    }

    public function testMissingBodyIsInvalid(): void
    {
        self::bootKernel();
        UserFactory::createOne(['username' => 'carol']);

        $tester = $this->tester();
        $exit = $tester->execute(['username' => 'carol']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('message body is required', $tester->getDisplay());
    }

    public function testUnknownTypeIsInvalid(): void
    {
        self::bootKernel();
        UserFactory::createOne(['username' => 'dan']);

        $tester = $this->tester();
        $exit = $tester->execute([
            'username' => 'dan',
            'body' => 'Hi',
            '--type' => 'not_a_real_type',
        ]);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertSame(0, $this->messageCount());
        $this->assertStringContainsString('Unknown --type', $tester->getDisplay());
    }

    public function testNoUsernameAndNoAllIsInvalid(): void
    {
        self::bootKernel();

        $tester = $this->tester();
        $exit = $tester->execute(['body' => 'Hi']);

        $this->assertSame(Command::INVALID, $exit);
        $this->assertStringContainsString('Provide a username', $tester->getDisplay());
    }
}

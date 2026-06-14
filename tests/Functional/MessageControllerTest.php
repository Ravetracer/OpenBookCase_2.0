<?php declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Tests\Factory\MessageFactory;
use App\Tests\Factory\UserFactory;
use Doctrine\ORM\EntityManagerInterface;

final class MessageControllerTest extends FunctionalTestCase
{
    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    public function testInboxRequiresAuth(): void
    {
        $this->client->request('GET', '/messages');
        $this->assertResponseStatusCodeSame(401);
    }

    public function testInboxListsRecipientMessagesOnly(): void
    {
        $user = $this->loginAsUser();
        $other = UserFactory::createOne();

        MessageFactory::createOne(['recipient' => $user, 'subject' => 'Mine One']);
        MessageFactory::createOne(['recipient' => $user, 'subject' => 'Mine Two']);
        MessageFactory::createOne(['recipient' => $other, 'subject' => 'Not Mine']);

        $this->client->request('GET', '/messages');
        $this->assertResponseIsSuccessful();

        $html = $this->client->getResponse()->getContent();
        $this->assertStringContainsString('Mine One', $html);
        $this->assertStringContainsString('Mine Two', $html);
        $this->assertStringNotContainsString('Not Mine', $html);
    }

    public function testOpeningInboxMarksAllReadAndZeroesUnreadCount(): void
    {
        $user = $this->loginAsUser();

        MessageFactory::createOne(['recipient' => $user]);
        MessageFactory::createOne(['recipient' => $user]);
        MessageFactory::new()->read()->create(['recipient' => $user]);

        /** @var MessageRepository $repo */
        $repo = static::getContainer()->get(MessageRepository::class);

        $this->em()->clear();
        $freshUser = $this->em()->getRepository(\App\Entity\User::class)->find($user->id);
        $this->assertSame(2, $repo->countUnread($freshUser), 'two unread before opening the inbox');

        // Opening the inbox marks everything read as a side effect.
        $this->client->request('GET', '/messages');
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $freshUser = $this->em()->getRepository(\App\Entity\User::class)->find($user->id);
        $this->assertSame(0, $repo->countUnread($freshUser), 'all messages should be read after opening the inbox');

        // Every message row now carries a readAt timestamp.
        $msgs = $this->em()->getRepository(Message::class)->findBy(['recipient' => $user->id]);
        $this->assertCount(3, $msgs);
        foreach ($msgs as $m) {
            $this->assertNotNull($m->readAt, 'each message should be marked read');
        }
    }

    public function testInboxEmptyForUserWithNoMessages(): void
    {
        $this->loginAsUser();
        $this->client->request('GET', '/messages');
        $this->assertResponseIsSuccessful();
    }
}

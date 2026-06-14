<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Message;
use App\Enums\MessageType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<Message> */
final class MessageFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Message::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'recipient' => UserFactory::new(),
            'type' => MessageType::Update,
            'subject' => self::faker()->sentence(4),
            'body' => self::faker()->paragraph(),
        ];
    }

    public function read(): self
    {
        return $this->with(['readAt' => \DateTimeImmutable::createFromMutable(self::faker()->dateTime())]);
    }
}

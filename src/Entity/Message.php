<?php

namespace App\Entity;

use App\Enums\MessageType;
use App\Repository\MessageRepository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

/**
 * A message delivered to a single user's inbox.
 *
 * By default messages are one-way from "the system" (release notes,
 * watchlist/wishlist notifications) — `sender` is null and no reply is possible.
 * The one exception is an **API-application conversation**: when `apiApplication`
 * is set, the message belongs to a scoped admin↔applicant thread, `sender` names
 * who wrote it, and replies are allowed (only between that applicant and admins).
 * No general peer-to-peer messaging surface is created.
 */
#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Index(name: 'recipient_read', columns: ['recipient_id', 'read_at'])]
class Message
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    public ?Ulid $id = null;

    #[ORM\ManyToOne(inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $recipient = null;

    /** Who wrote it; null = the system. Set only inside API-application threads. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $sender = null;

    /** When set, this message is part of that application's reply-enabled thread. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
    public ?ApiApplication $apiApplication = null;

    #[ORM\Column(length: 32, enumType: MessageType::class)]
    public MessageType $type = MessageType::Update;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $subject = null;

    #[ORM\Column(type: Types::TEXT)]
    public ?string $body = null;

    /** Optional deep-link target for watchlist/wishlist notifications. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Bookcase $relatedBookcase = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /** Null while unread; set to the read timestamp once seen. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $readAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function isRead(): bool
    {
        return $this->readAt !== null;
    }

    /** True when this message belongs to a reply-enabled API-application thread. */
    public function isThread(): bool
    {
        return $this->apiApplication !== null;
    }
}

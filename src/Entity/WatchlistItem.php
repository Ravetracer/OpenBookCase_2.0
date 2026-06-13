<?php

namespace App\Entity;

use App\Repository\WatchlistItemRepository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

/**
 * A user following a bookcase. When the bookcase (or, later, its wishlist)
 * changes, every watcher is notified via App\Service\MessageService.
 */
#[ORM\Entity(repositoryClass: WatchlistItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_watch_user_bookcase', columns: ['user_id', 'bookcase_id'])]
class WatchlistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    public ?Ulid $id = null;

    #[ORM\ManyToOne(inversedBy: 'watchlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'watchlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    public ?Bookcase $bookcase = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}

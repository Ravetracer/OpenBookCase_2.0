<?php

namespace App\Entity;

use App\Enums\WishlistItemStatus;
use App\Repository\WishlistItemRepository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: WishlistItemRepository::class)]
class WishlistItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['wishlist'])]
    public ?Ulid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['wishlist'])]
    public ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['wishlist'])]
    public ?string $isbn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['wishlist'])]
    public ?string $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['wishlist'])]
    public ?string $misc = null;

    #[ORM\ManyToOne(inversedBy: 'wishlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['wishlist'])]
    public ?Bookcase $bookcase = null;

    #[ORM\ManyToOne(inversedBy: 'wishlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['wishlist'])]
    public ?User $user = null;

    /**
     * The user who dropped a copy of the wished book at the bookcase (set while
     * the item is Dropped). Kept so the requester's pick-up / not-found
     * confirmation can notify them. Unidirectional — no inverse on User.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['wishlist'])]
    public ?User $droppedBy = null;

    #[ORM\Column(length: 255, enumType: WishlistItemStatus::class)]
    #[Groups(['wishlist'])]
    public WishlistItemStatus $status = WishlistItemStatus::Open;
}

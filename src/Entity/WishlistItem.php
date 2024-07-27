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
    private ?Ulid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['wishlist'])]
    private ?string $title = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['wishlist'])]
    private ?string $isbn = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['wishlist'])]
    private ?string $author = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['wishlist'])]
    private ?string $misc = null;

    #[ORM\ManyToOne(inversedBy: 'wishlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['wishlist'])]
    private ?Bookcase $bookcase = null;

    #[ORM\ManyToOne(inversedBy: 'wishlistItems')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['wishlist'])]
    private ?User $user = null;

    #[ORM\Column(length: 255, enumType: WishlistItemStatus::class)]
    #[Groups(['wishlist'])]
    private WishlistItemStatus $status = WishlistItemStatus::Open;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getIsbn(): ?string
    {
        return $this->isbn;
    }

    public function setIsbn(?string $isbn): self
    {
        $this->isbn = $isbn;

        return $this;
    }

    public function getAuthor(): ?string
    {
        return $this->author;
    }

    public function setAuthor(?string $author): self
    {
        $this->author = $author;

        return $this;
    }

    public function getMisc(): ?string
    {
        return $this->misc;
    }

    public function setMisc(?string $misc): self
    {
        $this->misc = $misc;

        return $this;
    }

    public function getBookcase(): ?Bookcase
    {
        return $this->bookcase;
    }

    public function setBookcase(?Bookcase $bookcase): self
    {
        $this->bookcase = $bookcase;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getStatus(): WishlistItemStatus
    {
        return $this->status;
    }

    public function setStatus(WishlistItemStatus $status): self
    {
        $this->status = $status;

        return $this;
    }
}

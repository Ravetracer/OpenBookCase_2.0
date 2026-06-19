<?php

namespace App\Entity;

use App\Enums\NotificationChannel;
use App\Repository\UserRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity(fields: ['username'], message: 'There is already an account with this username')]
#[UniqueEntity(fields: ['email'], message: 'There is already an account with this e-mail address')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    public ?Ulid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    public ?string $username = null;

    #[ORM\Column]
    public array $roles = [];

    #[ORM\Column]
    public ?string $password = null;

    #[ORM\Column]
    public ?string $email = null;

    #[ORM\Column(type: 'boolean')]
    public bool $isVerified = false;

    // When true the account is suspended: login is blocked in UserChecker.
    // Reversible from the admin user-management area.
    #[ORM\Column(options: ['default' => false])]
    public bool $isSuspended = false;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WishlistItem::class, orphanRemoval: true)]
    public Collection $wishlistItems;

    #[ORM\OneToMany(mappedBy: 'uploadedBy', targetEntity: Image::class)]
    public Collection $images;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Rating::class, orphanRemoval: true)]
    public Collection $ratings;

    #[ORM\OneToMany(mappedBy: 'recipient', targetEntity: Message::class, orphanRemoval: true)]
    public Collection $messages;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WatchlistItem::class, orphanRemoval: true)]
    public Collection $watchlistItems;

    #[ORM\Column(length: 16, enumType: NotificationChannel::class, options: ['default' => 'internal'])]
    public NotificationChannel $notificationChannel = NotificationChannel::Internal;

    #[ORM\Column(nullable: true)]
    public ?int $legacyId = null;

    #[ORM\Column(nullable: true)]
    public ?string $legacyPassword = null;

    #[ORM\Column(nullable: true)]
    public bool $legacyUser = false;

    #[ORM\Column(nullable: true)]
    public bool $legacyMigrated = false;

    #[ORM\Column(nullable: true)]
    #[Assert\Language]
    public ?string $language = null;

    // Personal default map view, applied on the map page when enabled.
    #[ORM\Column(nullable: true)]
    public ?float $homeLatitude = null;

    #[ORM\Column(nullable: true)]
    public ?float $homeLongitude = null;

    #[ORM\Column(nullable: true)]
    public ?int $homeZoom = null;

    // Optional user-chosen name for the home position (e.g. "Home", "Office").
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $homeLabel = null;

    #[ORM\Column(options: ['default' => false])]
    public bool $useHomeLocation = false;

    // Password reset: a one-time, hashed token (sha256) with an expiry. Cleared
    // once used. Never stores the raw token.
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $resetTokenHash = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $resetTokenExpiresAt = null;

    public function __construct()
    {
        $this->wishlistItems = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->ratings = new ArrayCollection();
        $this->messages = new ArrayCollection();
        $this->watchlistItems = new ArrayCollection();
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function addWishlistItem(WishlistItem $wishlistItem): self
    {
        if (!$this->wishlistItems->contains($wishlistItem)) {
            $this->wishlistItems->add($wishlistItem);
            $wishlistItem->user = $this;
        }

        return $this;
    }

    public function removeWishlistItem(WishlistItem $wishlistItem): self
    {
        // set the owning side to null (unless already changed)
        if ($this->wishlistItems->removeElement($wishlistItem) && $wishlistItem->user === $this) {
            $wishlistItem->user = null;
        }

        return $this;
    }

    public function addImage(Image $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->uploadedBy = $this;
        }

        return $this;
    }

    public function removeImage(Image $image): self
    {
        // set the owning side to null (unless already changed)
        if ($image->uploadedBy === $this && $this->images->removeElement($image)) {
            $image->uploadedBy = null;
        }

        return $this;
    }

    public function addRating(Rating $rating): self
    {
        if (!$this->ratings->contains($rating)) {
            $this->ratings->add($rating);
            $rating->user = $this;
        }

        return $this;
    }

    public function removeRating(Rating $rating): self
    {
        // set the owning side to null (unless already changed)
        if ($rating->user === $this && $this->ratings->removeElement($rating)) {
            $rating->user = null;
        }

        return $this;
    }
}

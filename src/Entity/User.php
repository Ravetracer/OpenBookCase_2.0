<?php

namespace App\Entity;

use App\Repository\UserRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Ulid;

use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity(fields: ['username'], message: 'There is already an account with this username')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    private ?Ulid $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $username = null;

    #[ORM\Column]
    private array $roles = [];

    #[ORM\Column]
    private ?string $password = null;

    #[ORM\Column]
    private ?string $email = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isVerified = false;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: WishlistItem::class, orphanRemoval: true)]
    private Collection $wishlistItems;

    #[ORM\OneToMany(mappedBy: 'uploadedBy', targetEntity: Image::class)]
    private Collection $images;

    #[ORM\OneToMany(mappedBy: 'user', targetEntity: Rating::class, orphanRemoval: true)]
    private Collection $ratings;

    #[ORM\Column(nullable: true)]
    private ?int $legacyId = null;

    #[ORM\Column(nullable: true)]
    private ?string $legacyPassword = null;

    #[ORM\Column(nullable: true)]
    private bool $legacyUser = false;

    #[ORM\Column(nullable: true)]
    private bool $legacyMigrated = false;

    #[ORM\Column(nullable: true)]
    #[Assert\Language]
    private ?string $language = null;

    public function __construct()
    {
        $this->wishlistItems = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->ratings = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): self
    {
        $this->username = $username;

        return $this;
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

    public function setRoles(array $roles): self
    {
        $this->roles = $roles;

        return $this;
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
    public function eraseCredentials()
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function isVerified(): bool
    {
        return $this->isVerified;
    }

    public function setIsVerified(bool $isVerified): self
    {
        $this->isVerified = $isVerified;

        return $this;
    }

    /**
     * @return Collection<int, WishlistItem>
     */
    public function getWishlistItems(): Collection
    {
        return $this->wishlistItems;
    }

    public function addWishlistItem(WishlistItem $wishlistItem): self
    {
        if (!$this->wishlistItems->contains($wishlistItem)) {
            $this->wishlistItems->add($wishlistItem);
            $wishlistItem->setUser($this);
        }

        return $this;
    }

    public function removeWishlistItem(WishlistItem $wishlistItem): self
    {
        // set the owning side to null (unless already changed)
        if ($this->wishlistItems->removeElement($wishlistItem) && $wishlistItem->getUser() === $this) {
            $wishlistItem->setUser(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, Image>
     */
    public function getImages(): Collection
    {
        return $this->images;
    }

    public function addImage(Image $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->setUploadedBy($this);
        }

        return $this;
    }

    public function removeImage(Image $image): self
    {
        // set the owning side to null (unless already changed)
        if ($this->images->removeElement($image) && $image->getUploadedBy() === $this) {
            $image->setUploadedBy(null);
        }

        return $this;
    }

    /**
     * @return Collection<int, Rating>
     */
    public function getRatings(): Collection
    {
        return $this->ratings;
    }

    public function addRating(Rating $rating): self
    {
        if (!$this->ratings->contains($rating)) {
            $this->ratings->add($rating);
            $rating->setUser($this);
        }

        return $this;
    }

    public function removeRating(Rating $rating): self
    {
        // set the owning side to null (unless already changed)
        if ($this->ratings->removeElement($rating) && $rating->getUser() === $this) {
            $rating->setUser(null);
        }

        return $this;
    }

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): User
    {
        $this->legacyId = $legacyId;

        return $this;
    }

    public function getLegacyPassword(): ?string
    {
        return $this->legacyPassword;
    }

    public function setLegacyPassword(?string $legacyPassword): User
    {
        $this->legacyPassword = $legacyPassword;

        return $this;
    }

    public function isLegacyUser(): bool
    {
        return $this->legacyUser;
    }

    public function setLegacyUser(bool $legacyUser): User
    {
        $this->legacyUser = $legacyUser;

        return $this;
    }

    public function isLegacyMigrated(): bool
    {
        return $this->legacyMigrated;
    }

    public function setLegacyMigrated(bool $legacyMigrated): User
    {
        $this->legacyMigrated = $legacyMigrated;

        return $this;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): User
    {
        $this->language = $language;

        return $this;
    }
}

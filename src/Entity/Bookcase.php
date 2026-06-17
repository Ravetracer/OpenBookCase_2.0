<?php

namespace App\Entity;

use App\Entity\Embeddables\Accessibility;
use App\Entity\Embeddables\Active;
use App\Entity\Embeddables\Address;
use App\Entity\Embeddables\Position;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Repository\BookcaseRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation as Serializer;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: BookcaseRepository::class)]
#[ORM\Index(name: 'latitude', columns: ['position_latitude'])]
#[ORM\Index(name: 'longitude', columns: ['position_longitude'])]
#[ORM\Index(name: 'legacyId', columns: ['legacy_id'])]
class Bookcase
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[Serializer\Groups(['bookcase'])]
    public ?Ulid $id = null;

    // Short, unique code for the public share link (https://obc.onl/{shortCode}).
    #[ORM\Column(length: 16, unique: true, nullable: true)]
    #[Serializer\Groups(['bookcase'])]
    public ?string $shortCode = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\NotNull]
    // No links in the title — those belong in the website/comment fields (anti-spam).
    #[Assert\Regex(pattern: '/https?:\/\/|www\./i', match: false, message: 'bookcase.title_no_url')]
    #[Serializer\Groups(['bookcase'])]
    public ?string $title = null;

    #[ORM\Embedded(class: Position::class)]
    #[Assert\Valid]
    #[Serializer\Groups(['bookcase'])]
    public ?Position $position = null;

    #[ORM\Column(length: 1024, nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    public ?string $webpage = null;

    // Whether the bookcase is a mobile installation (true) or a fixed one (false).
    #[ORM\Column]
    #[Serializer\Groups(['bookcase_detail'])]
    public bool $isMobile = false;

    // Whether this entry is an official BookCrossing release point/zone
    // (https://bookcrossing.com). Carried over from the legacy "bookcrosserzone" flag.
    #[ORM\Column(options: ['default' => false])]
    #[Serializer\Groups(['bookcase_detail'])]
    public bool $isBookcrossingZone = false;

    #[ORM\Embedded(class: Accessibility::class)]
    #[Assert\Valid]
    #[Serializer\Groups(['bookcase_detail'])]
    public ?Accessibility $accessibility = null;

    #[ORM\Column(nullable: false, enumType: EntryType::class)]
    #[Serializer\Exclude]
    public EntryType $entryType = EntryType::Bookcase;

    #[ORM\Column(length: 255, nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    public ?string $installationType = null;

    #[ORM\Column(nullable: false, enumType: MapSymbol::class)]
    #[Serializer\Exclude]
    public MapSymbol $mapSymbol = MapSymbol::Standard;

    #[ORM\Embedded(class: Active::class)]
    #[Serializer\Groups(['bookcase'])]
    public ?Active $active = null;

    #[ORM\Column]
    #[Serializer\Groups(['bookcase_detail'])]
    public bool $digitalMediaAllowed = false;

    #[ORM\ManyToMany(targetEntity: Caretaker::class, inversedBy: 'bookcases', cascade: ['persist'])]
    #[Serializer\Groups(['bookcase_detail'])]
    public Collection $caretakers;

    #[ORM\Embedded(class: Address::class)]
    #[Assert\Valid]
    #[Serializer\Groups(['bookcase_detail'])]
    public ?Address $address = null;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: OpeningTime::class)]
    #[Serializer\Groups(['bookcase_detail'])]
    public Collection $openingTimes;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: WishlistItem::class, orphanRemoval: true)]
    #[Serializer\Groups(['wishlist'])]
    public Collection $wishlistItems;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: Image::class, orphanRemoval: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    public Collection $images;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Serializer\Exclude]
    public ?int $legacyId = null;

    // Stable OpenStreetMap element reference for entries imported from OSM, stored
    // as "{type}{id}" (e.g. "n123456789") so node/way/relation ids never collide.
    // Unique (multiple NULLs allowed on SQLite) — re-imports match on this first.
    #[ORM\Column(length: 64, unique: true, nullable: true)]
    #[Serializer\Exclude]
    public ?string $osmId = null;

    // Provenance marker for imported data (e.g. 'osm'); NULL for app-created entries.
    #[ORM\Column(length: 32, nullable: true)]
    #[Serializer\Exclude]
    public ?string $source = null;

    // True when the title was auto-generated on import (from address tags or a
    // generic fallback) rather than a real OSM `name`. Drives the "help name this
    // bookcase" crowdsourcing prompt; cleared once a user gives it a proper title.
    #[ORM\Column(options: ['default' => false])]
    #[Serializer\Exclude]
    public bool $titleProvisional = false;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: Rating::class, orphanRemoval: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    public Collection $ratings;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: WatchlistItem::class, orphanRemoval: true)]
    #[Serializer\Exclude]
    public Collection $watchlistItems;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    public ?string $comment = null;

    public function __construct()
    {
        $this->caretakers = new ArrayCollection();
        $this->openingTimes = new ArrayCollection();
        $this->wishlistItems = new ArrayCollection();
        $this->images = new ArrayCollection();
        $this->accessibility = new Accessibility();
        $this->position = new Position();
        $this->active = new Active();
        $this->ratings = new ArrayCollection();
        $this->watchlistItems = new ArrayCollection();
    }

    /**
     * Creation timestamp, derived from the time-ordered ULID primary key — so we
     * get a "when was this added" date for the list view without a dedicated column.
     */
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->id?->getDateTime();
    }

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('entryType')]
    #[Serializer\Groups(['bookcase_detail'])]
    public function getEntryTypeValue(): ?string
    {
        return $this->entryType->value;
    }

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('mapSymbol')]
    #[Serializer\Groups(['bookcase'])]
    public function getMapSymbolValue(): ?string
    {
        return $this->mapSymbol->value;
    }

    public function addCaretaker(Caretaker $caretaker): self
    {
        if (!$this->caretakers->contains($caretaker)) {
            $this->caretakers->add($caretaker);
        }

        return $this;
    }

    public function removeCaretaker(Caretaker $caretaker): self
    {
        $this->caretakers->removeElement($caretaker);

        return $this;
    }

    public function addOpeningTime(OpeningTime $openingTime): self
    {
        if (!$this->openingTimes->contains($openingTime)) {
            $this->openingTimes->add($openingTime);
            $openingTime->bookcase = $this;
        }

        return $this;
    }

    public function removeOpeningTime(OpeningTime $openingTime): self
    {
        // set the owning side to null (unless already changed)
        if ($openingTime->bookcase === $this && $this->openingTimes->removeElement($openingTime)) {
            $openingTime->bookcase = null;
        }

        return $this;
    }

    public function addWishlistItem(WishlistItem $wishlistItem): self
    {
        if (!$this->wishlistItems->contains($wishlistItem)) {
            $this->wishlistItems->add($wishlistItem);
            $wishlistItem->bookcase = $this;
        }

        return $this;
    }

    public function removeWishlistItem(WishlistItem $wishlistItem): self
    {
        // set the owning side to null (unless already changed)
        if ($wishlistItem->bookcase === $this && $this->wishlistItems->removeElement($wishlistItem)) {
            $wishlistItem->bookcase = null;
        }

        return $this;
    }

    public function addImage(Image $image): self
    {
        if (!$this->images->contains($image)) {
            $this->images->add($image);
            $image->bookcase = $this;
        }

        return $this;
    }

    public function removeImage(Image $image): self
    {
        // set the owning side to null (unless already changed)
        if ($image->bookcase === $this && $this->images->removeElement($image)) {
            $image->bookcase = null;;
        }

        return $this;
    }

    public function addRating(Rating $rating): self
    {
        if (!$this->ratings->contains($rating)) {
            $this->ratings->add($rating);
            $rating->bookcase = $this;
        }

        return $this;
    }

    public function removeRating(Rating $rating): self
    {
        // set the owning side to null (unless already changed)
        if ($rating->bookcase === $this && $this->ratings->removeElement($rating)) {
            $rating->bookcase = null;
        }

        return $this;
    }
}

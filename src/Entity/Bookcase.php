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
#[ORM\Index(columns: ['position_latitude'], name: 'latitude')]
#[ORM\Index(columns: ['position_longitude'], name: 'longitude')]
#[ORM\Index(columns: ['legacy_id'], name: 'legacyId')]
class Bookcase
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[Serializer\Groups(['bookcase'])]
    private ?Ulid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\NotNull]
    #[Serializer\Groups(['bookcase'])]
    private ?string $title = null;

    #[ORM\Embedded(class: Position::class)]
    #[Assert\Valid]
    #[Serializer\Groups(['bookcase'])]
    private ?Position $position = null;

    #[ORM\Column(length: 1024, nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?string $webpage = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?string $mobility = null;

    #[ORM\Embedded(class: Accessibility::class)]
    #[Assert\Valid]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?Accessibility $accessibility = null;

    #[ORM\Column(nullable: false, enumType: EntryType::class)]
    #[Serializer\Exclude]
    private EntryType $entryType = EntryType::Bookcase;

    #[ORM\Column(length: 255, nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?string $installationType = null;

    #[ORM\Column(nullable: false, enumType: MapSymbol::class)]
    #[Serializer\Exclude]
    private MapSymbol $mapSymbol = MapSymbol::Standard;

    #[ORM\Embedded(class: Active::class)]
    #[Serializer\Groups(['bookcase'])]
    private ?Active $active = null;

    #[ORM\Column]
    #[Serializer\Groups(['bookcase_detail'])]
    private bool $digitalMediaAllowed = false;

    #[ORM\Column(length: 128, nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?string $shareLink = null;

    #[ORM\ManyToMany(targetEntity: Caretaker::class, inversedBy: 'bookcases')]
    #[Serializer\Groups(['bookcase_detail'])]
    private Collection $caretakers;

    #[ORM\Embedded(class: Address::class)]
    #[Assert\Valid]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?Address $address = null;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: OpeningTime::class)]
    #[Serializer\Groups(['bookcase_detail'])]
    private Collection $openingTimes;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: WishlistItem::class, orphanRemoval: true)]
    #[Serializer\Groups(['wishlist'])]
    private Collection $wishlistItems;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: Image::class, orphanRemoval: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private Collection $images;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Serializer\Exclude]
    private ?int $legacyId = null;

    #[ORM\OneToMany(mappedBy: 'bookcase', targetEntity: Rating::class, orphanRemoval: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private Collection $ratings;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Serializer\Groups(['bookcase_detail'])]
    private ?string $comment = null;

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
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getPosition(): ?Position
    {
        return $this->position;
    }

    public function setPosition(?Position $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function getWebpage(): ?string
    {
        return $this->webpage;
    }

    public function setWebpage(?string $webpage): self
    {
        $this->webpage = $webpage;

        return $this;
    }

    public function getMobility(): ?string
    {
        return $this->mobility;
    }

    public function setMobility(?string $mobility): self
    {
        $this->mobility = $mobility;

        return $this;
    }

    public function getAccessibility(): ?Accessibility
    {
        return $this->accessibility;
    }

    public function setAccessibility(?Accessibility $accessibility): self
    {
        $this->accessibility = $accessibility;

        return $this;
    }

    public function getActive(): ?Active
    {
        return $this->active;
    }

    public function setActive(?Active $active): self
    {
        $this->active = $active;

        return $this;
    }

    public function getEntryType(): EntryType
    {
        return $this->entryType;
    }

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('entryType')]
    #[Serializer\Groups(['bookcase_detail'])]
    public function getEntryTypeValue(): ?string
    {
        return $this->entryType->value;
    }

    public function setEntryType(EntryType $entryType): self
    {
        $this->entryType = $entryType;

        return $this;
    }

    public function getInstallationType(): ?string
    {
        return $this->installationType;
    }

    public function setInstallationType(?string $installationType): self
    {
        $this->installationType = $installationType;

        return $this;
    }

    public function getMapSymbol(): MapSymbol
    {
        return $this->mapSymbol;
    }

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('mapSymbol')]
    #[Serializer\Groups(['bookcase'])]
    public function getMapSymbolValue(): ?string
    {
        return $this->mapSymbol->value;
    }

    public function setMapSymbol(MapSymbol $mapSymbol): self
    {
        $this->mapSymbol = $mapSymbol;

        return $this;
    }

    public function isDigitalMediaAllowed(): ?bool
    {
        return $this->digitalMediaAllowed;
    }

    public function setDigitalMediaAllowed(bool $digitalMediaAllowed): self
    {
        $this->digitalMediaAllowed = $digitalMediaAllowed;

        return $this;
    }

    public function getShareLink(): ?string
    {
        return $this->shareLink;
    }

    public function setShareLink(?string $shareLink): self
    {
        $this->shareLink = $shareLink;

        return $this;
    }

    /**
     * @return Collection<int, Caretaker>
     */
    public function getCaretakers(): Collection
    {
        return $this->caretakers;
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

    public function getAddress(): ?Address
    {
        return $this->address;
    }

    public function setAddress(?Address $address): self
    {
        $this->address = $address;

        return $this;
    }

    /**
     * @return Collection<int, OpeningTime>
     */
    public function getOpeningTimes(): Collection
    {
        return $this->openingTimes;
    }

    public function addOpeningTime(OpeningTime $openingTime): self
    {
        if (!$this->openingTimes->contains($openingTime)) {
            $this->openingTimes->add($openingTime);
            $openingTime->setBookcase($this);
        }

        return $this;
    }

    public function removeOpeningTime(OpeningTime $openingTime): self
    {
        // set the owning side to null (unless already changed)
        if ($this->openingTimes->removeElement($openingTime) && $openingTime->getBookcase() === $this) {
            $openingTime->setBookcase(null);
        }

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
            $wishlistItem->setBookcase($this);
        }

        return $this;
    }

    public function removeWishlistItem(WishlistItem $wishlistItem): self
    {
        // set the owning side to null (unless already changed)
        if ($this->wishlistItems->removeElement($wishlistItem) && $wishlistItem->getBookcase() === $this) {
            $wishlistItem->setBookcase(null);
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
            $image->setBookcase($this);
        }

        return $this;
    }

    public function removeImage(Image $image): self
    {
        // set the owning side to null (unless already changed)
        if ($this->images->removeElement($image) && $image->getBookcase() === $this) {
            $image->setBookcase(null);
        }

        return $this;
    }

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): self
    {
        $this->legacyId = $legacyId;

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
            $rating->setBookcase($this);
        }

        return $this;
    }

    public function removeRating(Rating $rating): self
    {
        // set the owning side to null (unless already changed)
        if ($this->ratings->removeElement($rating) && $rating->getBookcase() === $this) {
            $rating->setBookcase(null);
        }

        return $this;
    }

    public function setComment(?string $comment): self
    {
        $this->comment = $comment;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }
}

<?php

namespace App\Entity;

use App\Entity\Embeddables\Address;
use App\Repository\CaretakerRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: CaretakerRepository::class)]
class Caretaker
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    private ?Ulid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $contact = null;

    #[ORM\Embedded(class: Address::class)]
    private ?Address $address = null;

    #[ORM\ManyToMany(targetEntity: Bookcase::class, mappedBy: 'caretakers')]
    private Collection $bookcases;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $legacyContactData = null;

    public function __construct()
    {
        $this->bookcases = new ArrayCollection();
    }

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getContact(): ?string
    {
        return $this->contact;
    }

    public function setContact(?string $contact): self
    {
        $this->contact = $contact;

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
     * @return Collection<int, Bookcase>
     */
    public function getBookcases(): Collection
    {
        return $this->bookcases;
    }

    public function addBookcase(Bookcase $bookcase): self
    {
        if (!$this->bookcases->contains($bookcase)) {
            $this->bookcases->add($bookcase);
            $bookcase->addCaretaker($this);
        }

        return $this;
    }

    public function removeBookcase(Bookcase $bookcase): self
    {
        if ($this->bookcases->removeElement($bookcase)) {
            $bookcase->removeCaretaker($this);
        }

        return $this;
    }

    public function getLegacyContactData(): ?string
    {
        return $this->legacyContactData;
    }

    public function setLegacyContactData(?string $legacyContactData): self
    {
        $this->legacyContactData = $legacyContactData;

        return $this;
    }
}

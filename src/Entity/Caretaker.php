<?php

namespace App\Entity;

use App\Entity\Embeddables\Address;
use App\Repository\CaretakerRepository;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation\Groups;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: CaretakerRepository::class)]
class Caretaker
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['caretaker'])]
    public ?Ulid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['caretaker'])]
    public ?string $name = null;

    #[ORM\Column(length: 512, nullable: true)]
    #[Groups(['caretaker'])]
    public ?string $contact = null;

    #[ORM\Embedded(class: Address::class)]
    #[Groups(['caretaker'])]
    public ?Address $address = null;

    #[ORM\ManyToMany(targetEntity: Bookcase::class, mappedBy: 'caretakers')]
    #[Groups(['caretaker'])]
    public Collection $bookcases;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['caretaker'])]
    public ?string $legacyContactData = null;

    public function __construct()
    {
        $this->bookcases = new ArrayCollection();
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
}

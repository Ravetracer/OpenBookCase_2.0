<?php

namespace App\Entity;

use App\Repository\OpeningTimeRepository;

use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: OpeningTimeRepository::class)]
class OpeningTime
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    private ?Ulid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $open_time = null;

    #[ORM\Column(nullable: true)]
    private ?bool $twenty_for_seven = null;

    #[ORM\ManyToOne(inversedBy: 'openingTimes')]
    private ?Bookcase $bookcase = null;

    public function getId(): ?Ulid
    {
        return $this->id;
    }

    public function getOpenTime(): ?string
    {
        return $this->open_time;
    }

    public function setOpenTime(?string $open_time): self
    {
        $this->open_time = $open_time;

        return $this;
    }

    public function isTwentyForSeven(): ?bool
    {
        return $this->twenty_for_seven;
    }

    public function setTwentyForSeven(bool $twenty_for_seven): self
    {
        $this->twenty_for_seven = $twenty_for_seven;

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
}

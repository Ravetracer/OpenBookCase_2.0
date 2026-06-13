<?php

namespace App\Entity;

use App\Repository\OpeningTimeRepository;

use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation\Groups;
use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: OpeningTimeRepository::class)]
class OpeningTime
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[Groups(['bookcase'])]
    public ?Ulid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['bookcase'])]
    public ?string $open_time = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['bookcase'])]
    public ?bool $twenty_for_seven = null;

    #[ORM\ManyToOne(inversedBy: 'openingTimes')]
    #[Groups(['bookcase'])]
    public ?Bookcase $bookcase = null;
}

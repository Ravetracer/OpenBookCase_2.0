<?php

namespace App\Entity\Embeddables;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation\Groups;

#[ORM\Embeddable]
class Address
{
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address'])]
    public ?string $street = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address'])]
    public ?string $houseNumber = null;

    #[ORM\Column(length: 128, nullable: true)]
    #[Groups(['address'])]
    public ?string $zipcode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address'])]
    public ?string $city = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['address'])]
    public ?string $additionalData = null;
}

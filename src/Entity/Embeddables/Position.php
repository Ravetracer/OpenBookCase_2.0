<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Embeddable]
class Position
{
    #[ORM\Column]
    #[Groups(['bookcase'])]
    #[Assert\Range(
        min: -90,
        max: 90,
        notInRangeMessage: 'position.latitude_out_of_range',
    )]
    public ?float $latitude = null;

    #[ORM\Column]
    #[Groups(['bookcase'])]
    #[Assert\Range(
        min: -180,
        max: 180,
        notInRangeMessage: 'position.longitude_out_of_range',
    )]
    public ?float $longitude = null;
}

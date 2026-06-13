<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use Doctrine\ORM\Mapping as ORM;
use JMS\Serializer\Annotation\Groups;

#[ORM\Embeddable]
class Position
{
    #[ORM\Column]
    #[Groups(['bookcase'])]
    public ?float $latitude = null;

    #[ORM\Column]
    #[Groups(['bookcase'])]
    public ?float $longitude = null;
}

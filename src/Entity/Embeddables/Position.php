<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Position
{
    #[ORM\Column]
    private ?float $latitude = null;

    #[ORM\Column]
    private ?float $longitude = null;

    public function getLatitude(): ?float
    {
        return $this->latitude;
    }

    public function setLatitude(float $latitude): self
    {
        $this->latitude = $latitude;

        return $this;
    }

    public function getLongitude(): ?float
    {
        return $this->longitude;
    }

    public function setLongitude(float $longitude): self
    {
        $this->longitude = $longitude;

        return $this;
    }
}

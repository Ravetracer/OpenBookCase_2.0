<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Accessibility
{
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $level = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    public function getLevel(): ?int
    {
        return $this->level;
    }

    public function setLevel(?int $level): self
    {
        $this->level = $level;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }
}

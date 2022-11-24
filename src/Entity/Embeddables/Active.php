<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use App\Enums\ActiveStatus;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
class Active
{
    #[ORM\Column(nullable: false, enumType: ActiveStatus::class)]
    private ActiveStatus $status = ActiveStatus::Active;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $statusDescription = null;

    public function getStatus(): ActiveStatus
    {
        return $this->status;
    }

    public function setStatus(ActiveStatus $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getStatusDescription(): ?string
    {
        return $this->statusDescription;
    }

    public function setStatusDescription(?string $statusDescription): self
    {
        $this->statusDescription = $statusDescription;

        return $this;
    }
}

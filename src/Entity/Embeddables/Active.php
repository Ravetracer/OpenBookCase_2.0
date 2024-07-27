<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use App\Enums\ActiveStatus;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation as Serializer;

#[ORM\Embeddable]
class Active
{
    #[ORM\Column(nullable: false, enumType: ActiveStatus::class)]
    #[Serializer\Exclude]
    private ActiveStatus $status = ActiveStatus::Active;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Serializer\Groups(['bookcase'])]
    private ?string $statusDescription = null;

    public function getStatus(): ActiveStatus
    {
        return $this->status;
    }

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('status')]
    #[Serializer\Groups(['bookcase'])]
    public function getStatusValue(): ?string
    {
        return $this->status->value;
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

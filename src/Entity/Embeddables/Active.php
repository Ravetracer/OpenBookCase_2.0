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
    public ActiveStatus $status = ActiveStatus::Active;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Serializer\Groups(['bookcase'])]
    public ?string $statusDescription = null;

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('status')]
    #[Serializer\Groups(['bookcase'])]
    public function getStatusValue(): ?string
    {
        return $this->status->value;
    }
}

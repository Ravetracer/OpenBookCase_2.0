<?php declare(strict_types=1);
/**
 * @author: Christian Nielebock <me@ravetracer.de>
 * Date: 15.11.22
 */

namespace App\Entity\Embeddables;

use App\Enums\AccessibilityLevel;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use JMS\Serializer\Annotation as Serializer;

#[ORM\Embeddable]
class Accessibility
{
    // Traffic-light access level (None/Partial/Full). Int-backed enum on the
    // existing INTEGER column. Excluded + exposed as a plain int via the virtual
    // property below (the project's JMS pattern for enums — see Active::status).
    #[ORM\Column(type: Types::INTEGER, nullable: true, enumType: AccessibilityLevel::class)]
    #[Serializer\Exclude]
    public ?AccessibilityLevel $level = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Serializer\Groups(['bookcase'])]
    public ?string $description = null;

    #[Serializer\VirtualProperty]
    #[Serializer\SerializedName('level')]
    #[Serializer\Groups(['bookcase'])]
    public function getLevelValue(): ?int
    {
        return $this->level?->value;
    }
}

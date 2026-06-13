<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

/**
 * Archive of a soft-deleted bookcase. When a user deletes an entry it is removed
 * from the live `bookcase` table but a full structured snapshot is kept here,
 * together with who deleted it, when, and the reason they gave — so a deletion is
 * recoverable and auditable rather than destructive.
 */
#[ORM\Entity]
#[ORM\Table(name: 'deleted_bookcase')]
class DeletedBookcase
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    #[ORM\Column(type: 'ulid', unique: true)]
    public ?Ulid $id = null;

    /** The live id the entry had before deletion (base-32 ULID string, archival). */
    #[ORM\Column(length: 26)]
    public string $originalId;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $title = null;

    /** Full structured snapshot of the deleted bookcase (JMS-serialized fields). */
    #[ORM\Column(type: Types::JSON)]
    public array $payload = [];

    /** Why the entry was deleted — required from the user at deletion time. */
    #[ORM\Column(type: Types::TEXT)]
    public string $reason;

    /** Username of whoever performed the deletion (null if not resolvable). */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $deletedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $deletedAt;

    public function __construct()
    {
        $this->deletedAt = new \DateTimeImmutable();
    }
}

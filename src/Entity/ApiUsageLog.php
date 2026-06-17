<?php

namespace App\Entity;

use App\Repository\ApiUsageLogRepository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;

/**
 * One row per authenticated /api/v1 request (i.e. requests carrying an OAuth token —
 * anonymous public-data reads are not logged). Written from a kernel.terminate
 * subscriber after the response is sent, so logging never delays the client.
 *
 * The link to the originating application is kept both as a FK (nullable, SET NULL so
 * the log survives the app being deleted) and as the raw client id string, so usage
 * stays attributable even after the ApiApplication row is gone.
 */
#[ORM\Entity(repositoryClass: ApiUsageLogRepository::class)]
#[ORM\Index(name: 'api_usage_created', columns: ['created_at'])]
#[ORM\Index(name: 'api_usage_client', columns: ['oauth_client_id'])]
#[ORM\Index(name: 'api_usage_route', columns: ['route_name'])]
class ApiUsageLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    public ?Ulid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?ApiApplication $apiApplication = null;

    /** Raw OAuth client id (kept even if the application is later deleted). */
    #[ORM\Column(length: 64, nullable: true)]
    public ?string $oauthClientId = null;

    /** The user the token acted as (the contributor). Nullable + SET NULL. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $actingUser = null;

    #[ORM\Column(length: 10)]
    public string $method = 'GET';

    /** Symfony route name (low-cardinality; drives the per-route admin filter + Matomo action). */
    #[ORM\Column(length: 128, nullable: true)]
    public ?string $routeName = null;

    #[ORM\Column(length: 255)]
    public string $path = '';

    /**
     * Decoded request payload (write body or read query params). Multipart/binary
     * bodies (image uploads) are omitted — only a small note is stored.
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $requestPayload = null;

    #[ORM\Column(type: Types::SMALLINT)]
    public int $statusCode = 200;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}

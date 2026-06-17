<?php

namespace App\Entity;

use App\Enums\ApiApplicationStatus;
use App\Enums\ApiClientType;
use App\Repository\ApiApplicationRepository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use Symfony\Bridge\Doctrine\IdGenerator\UlidGenerator;
use Symfony\Component\Uid\Ulid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A developer's application for API access. Created in `Pending` state from the
 * user's profile, vetted by an admin, then `Approved` / `Denied` (and possibly
 * `Revoked` later). On approval (Phase 2) it becomes the backing record for an
 * OAuth2 client; the conversation with the admin lives in scoped Message threads
 * (see Message.apiApplication).
 */
#[ORM\Entity(repositoryClass: ApiApplicationRepository::class)]
#[ORM\Index(name: 'api_application_status', columns: ['status'])]
class ApiApplication
{
    /**
     * The OAuth scopes a developer may request (and that get granted on approval).
     * Write/user-data only — public-data reads need no token. There is deliberately
     * no scope for account management (email/password/notifications/deletion).
     */
    public const AVAILABLE_SCOPES = [
        'bookcases.write',
        'bookcases.delete',
        'images.write',
        'wishlist.write',
        'watchlist.write',
        'home.write',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'CUSTOM')]
    #[ORM\Column(type: 'ulid', unique: true)]
    #[ORM\CustomIdGenerator(class: UlidGenerator::class)]
    public ?Ulid $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $applicant = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $appName = null;

    /** Free-text reason / use case: how and where the data will be used. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 20, max: 4000)]
    public ?string $useCase = null;

    #[ORM\Column(length: 16, enumType: ApiClientType::class)]
    public ApiClientType $clientType = ApiClientType::PublicClient;

    /** @var string[] OAuth redirect URIs the app will use. */
    #[ORM\Column(type: Types::JSON)]
    public array $redirectUris = [];

    /** @var string[] Scopes requested by the applicant (granted on approval). */
    #[ORM\Column(type: Types::JSON)]
    public array $requestedScopes = [];

    #[ORM\Column(length: 16, enumType: ApiApplicationStatus::class)]
    public ApiApplicationStatus $status = ApiApplicationStatus::Pending;

    /** Reason shown to the applicant when denied or a previously-approved app is revoked. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $decisionReason = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?User $decidedBy = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    public \DateTimeImmutable $createdAt;

    /** Identifier of the provisioned OAuth client (set on approval; links to the bundle's client). */
    #[ORM\Column(length: 64, nullable: true, unique: true)]
    public ?string $oauthClientId = null;

    /**
     * The raw client secret for a confidential client — held ONLY between approval
     * and the applicant's first acknowledgement, then nulled (shown once). The
     * bundle keeps its own copy for verification; we never re-surface this one.
     */
    #[ORM\Column(length: 128, nullable: true)]
    public ?string $oauthPlainSecret = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}

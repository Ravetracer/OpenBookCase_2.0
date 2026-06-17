<?php declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\ApiApplication;
use App\Enums\ApiApplicationStatus;
use App\Enums\ApiClientType;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<ApiApplication>
 */
final class ApiApplicationFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return ApiApplication::class;
    }

    protected function defaults(): array|callable
    {
        return [
            'applicant' => UserFactory::new(),
            'appName' => self::faker()->unique()->company(),
            'useCase' => self::faker()->paragraph() . ' ' . self::faker()->sentence(),
            'clientType' => ApiClientType::PublicClient,
            'redirectUris' => ['https://example.com/callback'],
            'requestedScopes' => ['bookcases.write'],
            'status' => ApiApplicationStatus::Pending,
        ];
    }

    public function approved(): self
    {
        return $this->with(['status' => ApiApplicationStatus::Approved]);
    }

    public function denied(string $reason = 'Not enough detail.'): self
    {
        return $this->with(['status' => ApiApplicationStatus::Denied, 'decisionReason' => $reason]);
    }
}

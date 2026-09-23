<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * /api/v1 profile actions of the token's user — only the home map location.
 * Unlike the website form ({@see UserAccountService::updateHome()}) this never
 * toggles useHomeLocation (the "center the map on my home" switch).
 */
final class ApiProfileService
{
    public function __construct(
        private readonly ApiInput $input,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Stores `latitude`/`longitude` (required, 400 when invalid — checked before
     * anything changes), `zoom` (clamped to 1–19) and `label` (≤ 50 chars, blank → none).
     *
     * @param array<string, mixed> $data the JSON body
     */
    public function setHome(User $user, array $data): void
    {
        $latitude = $this->input->coordinate($data['latitude'] ?? null, 90);
        $longitude = $this->input->coordinate($data['longitude'] ?? null, 180);

        $user->homeLatitude = $latitude;
        $user->homeLongitude = $longitude;
        if (isset($data['zoom'])) {
            $user->homeZoom = max(1, min(19, (int) $data['zoom']));
        }
        if (array_key_exists('label', $data)) {
            $label = (string) $this->input->string($data['label']);
            $user->homeLabel = $label !== '' ? mb_substr($label, 0, 50) : null;
        }

        $this->entityManager->flush();
    }

    /** @return array<string, mixed> */
    public function homeToArray(User $user): array
    {
        return [
            'latitude' => $user->homeLatitude,
            'longitude' => $user->homeLongitude,
            'zoom' => $user->homeZoom,
            'label' => $user->homeLabel,
            'useHomeLocation' => $user->useHomeLocation,
        ];
    }
}

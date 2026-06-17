<?php

namespace App\Controller\Api\V1;

use App\Entity\User;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — limited profile actions for the token's user. Deliberately scoped:
 * only the home map location (scope `home.write` → ROLE_OAUTH2_HOME.WRITE). There are
 * intentionally NO endpoints for e-mail/password/notifications/account deletion.
 *
 * Unlike the website's home form, this does NOT toggle the "center the map on my home"
 * switch (useHomeLocation) — it only stores the coordinates/label.
 */
#[Route('/api/v1/profile', name: 'api_v1_profile_')]
class ProfileApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/home', name: 'home', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_HOME.WRITE')]
    public function setHome(Request $request): JsonResponse
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            $data = [];
        }

        $lat = $data['latitude'] ?? null;
        $lon = $data['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lon < -180 || (float) $lon > 180) {
            return new JsonResponse(['error' => 'Invalid coordinates.'], Response::HTTP_BAD_REQUEST);
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->homeLatitude = (float) $lat;
        $user->homeLongitude = (float) $lon;
        if (isset($data['zoom'])) {
            $user->homeZoom = max(1, min(19, (int) $data['zoom']));
        }
        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            $user->homeLabel = $label !== '' ? mb_substr($label, 0, 50) : null;
        }
        // NB: useHomeLocation (the "center map on home" switch) is intentionally untouched.

        $this->entityManager->flush();

        return new JsonResponse([
            'latitude' => $user->homeLatitude,
            'longitude' => $user->homeLongitude,
            'zoom' => $user->homeZoom,
            'label' => $user->homeLabel,
            'useHomeLocation' => $user->useHomeLocation,
        ]);
    }
}

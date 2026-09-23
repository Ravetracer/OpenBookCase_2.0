<?php

namespace App\Controller\Api\V1;

use App\Entity\User;
use App\Service\ApiInput;
use App\Service\ApiProfileService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
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
        private readonly ApiInput $input,
        private readonly ApiProfileService $profile,
    ) {
    }

    #[Route('/home', name: 'home', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_HOME.WRITE')]
    public function setHome(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $this->profile->setHome($user, $this->input->jsonBody($request));

        return new JsonResponse($this->profile->homeToArray($user));
    }
}

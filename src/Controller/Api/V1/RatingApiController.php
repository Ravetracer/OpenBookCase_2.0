<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Service\ApiInput;
use App\Service\RatingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — the token user's rating of a bookcase. Each user has at most
 * one rating per bookcase, so this is a singleton resource: PUT upserts it,
 * DELETE removes it. Writing needs the `ratings.write` scope (role
 * ROLE_OAUTH2_RATINGS.WRITE); the rating belongs to the token's user, exactly
 * like the website's "Rate" popover.
 *
 * Reading returns the public aggregate only (count + average) — individual
 * users' ratings are never exposed.
 */
#[Route('/api/v1/bookcases/{bookcase}/rating', name: 'api_v1_rating_')]
class RatingApiController extends AbstractController
{
    public function __construct(
        private readonly ApiInput $input,
        private readonly RatingService $ratingService,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(Bookcase $bookcase): JsonResponse
    {
        return new JsonResponse($this->ratingService->publicStats($bookcase));
    }

    #[Route('', name: 'upsert', methods: ['PUT'])]
    #[IsGranted('ROLE_OAUTH2_RATINGS.WRITE')]
    public function upsert(Request $request, Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->input->jsonBody($request);
        $value = filter_var($data['value'] ?? null, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) ?? 0;
        if ($value < 1 || $value > 5) {
            return new ApiProblem('A "value" between 1 and 5 is required.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $setComment = array_key_exists('comment', $data);
        $this->ratingService->upsert($bookcase, $user, $value, $setComment, $setComment ? $this->input->string($data['comment']) : null);

        return new JsonResponse(['userValue' => $value] + $this->ratingService->publicStats($bookcase));
    }

    #[Route('', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_RATINGS.WRITE')]
    public function delete(Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $this->ratingService->remove($bookcase, $user);

        return new JsonResponse(['status' => 'deleted'] + $this->ratingService->publicStats($bookcase));
    }
}

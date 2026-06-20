<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\Rating;
use App\Entity\User;
use App\Repository\RatingRepository;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
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
        private readonly EntityManagerInterface $entityManager,
        private readonly RatingRepository $ratings,
    ) {
    }

    #[Route('', name: 'show', methods: ['GET'])]
    public function show(Bookcase $bookcase): JsonResponse
    {
        return new JsonResponse($this->stats($bookcase));
    }

    #[Route('', name: 'upsert', methods: ['PUT'])]
    #[IsGranted('ROLE_OAUTH2_RATINGS.WRITE')]
    public function upsert(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->jsonBody($request);
        $value = (int) ($data['value'] ?? 0);
        if ($value < 1 || $value > 5) {
            return new JsonResponse(['error' => 'A "value" between 1 and 5 is required.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var User $user */
        $user = $this->getUser();

        // One rating per user per bookcase: update the existing one or create it.
        $existing = $this->ratings->findOneBy(['bookcase' => $bookcase, 'user' => $user]);
        $rating = $existing ?? new Rating();
        $rating->bookcase = $bookcase;
        $rating->user = $user;
        $rating->value = $value;
        if (array_key_exists('comment', $data)) {
            $rating->comment = $this->nullableString($data['comment']);
        }

        $this->entityManager->persist($rating);
        $this->entityManager->flush();

        // Keep the in-memory ratings collection in sync so the aggregate below
        // reflects a brand-new rating on the first vote (mirrors BookcaseController::rate).
        if ($existing === null) {
            $bookcase->addRating($rating);
        }

        return new JsonResponse(['userValue' => $value] + $this->stats($bookcase));
    }

    #[Route('', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_RATINGS.WRITE')]
    public function delete(Bookcase $bookcase): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();

        $existing = $this->ratings->findOneBy(['bookcase' => $bookcase, 'user' => $user]);
        if ($existing !== null) {
            $bookcase->removeRating($existing);
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }

        return new JsonResponse(['status' => 'deleted'] + $this->stats($bookcase));
    }

    /** @return array{count: int, average: float, rounded: int} */
    private function stats(Bookcase $bookcase): array
    {
        $values = array_map(static fn (Rating $r) => (int) $r->value, $bookcase->ratings->toArray());
        $count = count($values);
        $average = $count > 0 ? array_sum($values) / $count : 0.0;

        return [
            'count' => $count,
            'average' => $count > 0 ? round($average, 2) : 0.0,
            'rounded' => (int) round($average),
        ];
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}

<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\OpeningTime;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — opening times of a bookcase. Like caretakers, these are
 * intrinsic bookcase data, so writing uses the `bookcases.write` scope
 * (role ROLE_OAUTH2_BOOKCASES.WRITE). Reading is open.
 *
 * An opening time carries a free-text `openTime` (e.g. "Mo-Fr 08:00-18:00") OR
 * the `twentyFourSeven` flag (always open). One of the two must be set — an
 * empty opening time is meaningless (mirrors the website edit form).
 */
#[Route('/api/v1/bookcases/{bookcase}/opening-times', name: 'api_v1_opening_times_')]
class OpeningTimeApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase): JsonResponse
    {
        $items = [];
        foreach ($bookcase->openingTimes as $openingTime) {
            $items[] = $this->toArray($openingTime);
        }

        return new JsonResponse(['openingTimes' => $items]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function add(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->jsonBody($request);

        $openTime = $this->nullableString($data['openTime'] ?? null);
        $twentyFourSeven = (bool) ($data['twentyFourSeven'] ?? false);
        if ($openTime === null && !$twentyFourSeven) {
            return new JsonResponse(
                ['error' => 'An opening time needs an "openTime" text or "twentyFourSeven": true.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $openingTime = new OpeningTime();
        $openingTime->open_time = $openTime;
        $openingTime->twenty_for_seven = $twentyFourSeven;
        $bookcase->addOpeningTime($openingTime);

        $this->entityManager->persist($openingTime);
        $this->entityManager->flush();

        return new JsonResponse($this->toArray($openingTime), Response::HTTP_CREATED);
    }

    #[Route('/{openingTime}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function update(Request $request, Bookcase $bookcase, OpeningTime $openingTime): JsonResponse
    {
        if ($openingTime->bookcase?->id?->equals($bookcase->id) !== true) {
            return new JsonResponse(['error' => 'Opening time not found on this bookcase.'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->jsonBody($request);
        if (array_key_exists('openTime', $data)) {
            $openingTime->open_time = $this->nullableString($data['openTime']);
        }
        if (array_key_exists('twentyFourSeven', $data)) {
            $openingTime->twenty_for_seven = (bool) $data['twentyFourSeven'];
        }

        $this->entityManager->flush();

        return new JsonResponse($this->toArray($openingTime));
    }

    #[Route('/{openingTime}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function delete(Bookcase $bookcase, OpeningTime $openingTime): JsonResponse
    {
        if ($openingTime->bookcase?->id?->equals($bookcase->id) !== true) {
            return new JsonResponse(['error' => 'Opening time not found on this bookcase.'], Response::HTTP_NOT_FOUND);
        }

        // orphanRemoval on Bookcase::openingTimes deletes the row once detached.
        $bookcase->removeOpeningTime($openingTime);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'deleted']);
    }

    /** @return array<string, mixed> */
    private function toArray(OpeningTime $openingTime): array
    {
        return [
            'id' => (string) $openingTime->id,
            'openTime' => $openingTime->open_time,
            'twentyFourSeven' => (bool) $openingTime->twenty_for_seven,
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

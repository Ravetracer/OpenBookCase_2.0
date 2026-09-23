<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\OpeningTime;
use App\Http\ApiProblem;
use App\Service\ApiInput;
use App\Service\OpeningTimeService;
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
        private readonly ApiInput $input,
        private readonly OpeningTimeService $openingTimes,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase): JsonResponse
    {
        $items = [];
        foreach ($bookcase->openingTimes as $openingTime) {
            $items[] = $this->openingTimes->toArray($openingTime);
        }

        return new JsonResponse(['openingTimes' => $items]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function add(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->input->jsonBody($request);

        $openingTime = new OpeningTime();
        $openingTime->open_time = $this->input->string($data['openTime'] ?? null);
        $openingTime->twenty_for_seven = (bool) ($data['twentyFourSeven'] ?? false);
        if ($openingTime->open_time === null && !$openingTime->twenty_for_seven) {
            return new ApiProblem('An opening time needs an "openTime" text or "twentyFourSeven": true.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($violations = $this->openingTimes->create($bookcase, $openingTime)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        return new JsonResponse($this->openingTimes->toArray($openingTime), Response::HTTP_CREATED);
    }

    #[Route('/{openingTime}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function update(Request $request, Bookcase $bookcase, OpeningTime $openingTime): JsonResponse
    {
        if ($openingTime->bookcase?->id?->equals($bookcase->id) !== true) {
            return new ApiProblem('Opening time not found on this bookcase.', Response::HTTP_NOT_FOUND);
        }

        $data = $this->input->jsonBody($request);
        if (array_key_exists('openTime', $data)) {
            $openingTime->open_time = $this->input->string($data['openTime']);
        }
        if (array_key_exists('twentyFourSeven', $data)) {
            $openingTime->twenty_for_seven = (bool) $data['twentyFourSeven'];
        }

        if ($violations = $this->openingTimes->update($openingTime)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        return new JsonResponse($this->openingTimes->toArray($openingTime));
    }

    #[Route('/{openingTime}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function delete(Bookcase $bookcase, OpeningTime $openingTime): JsonResponse
    {
        if ($openingTime->bookcase?->id?->equals($bookcase->id) !== true) {
            return new ApiProblem('Opening time not found on this bookcase.', Response::HTTP_NOT_FOUND);
        }

        $this->openingTimes->delete($bookcase, $openingTime);

        return new JsonResponse(['status' => 'deleted']);
    }
}

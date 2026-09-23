<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\User;
use App\Http\ApiProblem;
use App\Repository\BookcaseRepository;
use App\Service\ApiInput;
use App\Service\BookcaseApiMapper;
use App\Service\BookcaseMarkerService;
use App\Service\BookcaseService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — bookcases.
 *
 * Reads are open (no token). Writes require an OAuth2 access token whose scope maps
 * to the role checked by #[IsGranted] (the bundle exposes scope `bookcases.write` as
 * role `ROLE_OAUTH2_BOOKCASES.WRITE`, etc.). Writes act as the token's user — exactly
 * the same user the website controllers get via #[CurrentUser].
 *
 * JSON in, JSON out. The detail shape mirrors the website's single-bookcase endpoint
 * (same JMS groups); the bbox shape mirrors the map's marker payload.
 */
#[Route('/api/v1/bookcases', name: 'api_v1_bookcases_')]
class BookcaseApiController extends AbstractController
{
    private const BBOX_MAX = 1000;

    public function __construct(
        private readonly ApiInput $input,
        private readonly BookcaseRepository $bookcases,
        private readonly BookcaseService $bookcaseService,
        private readonly BookcaseApiMapper $mapper,
        private readonly BookcaseMarkerService $markerService,
    ) {
    }

    // ── Reads (open) ──────────────────────────────────────────────────────

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        foreach (['latMin', 'latMax', 'lonMin', 'lonMax'] as $p) {
            if ($request->query->get($p) === null) {
                return new ApiProblem('Missing bounding box parameter: ' . $p, Response::HTTP_BAD_REQUEST);
            }
        }
        $latMin = (float) $request->query->get('latMin');
        $latMax = (float) $request->query->get('latMax');
        $lonMin = (float) $request->query->get('lonMin');
        $lonMax = (float) $request->query->get('lonMax');
        $limit = max(1, min(self::BBOX_MAX, (int) $request->query->get('limit', 500)));
        $offset = max(0, (int) $request->query->get('offset', 0));

        $total = $this->bookcases->countByBoundingBox($latMin, $latMax, $lonMin, $lonMax);
        $rows = $this->bookcases->findByBoundingBoxLight($latMin, $latMax, $lonMin, $lonMax, $limit, $offset);

        return new JsonResponse([
            'total' => $total,
            'offset' => $offset,
            'limit' => $limit,
            'markers' => array_map($this->markerService->apiFromRow(...), $rows),
        ]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $bookcase = $this->bookcases->findOneWithRelations($id);
        if ($bookcase === null) {
            return new ApiProblem('Bookcase not found.', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse($this->bookcaseService->detailJson($bookcase), Response::HTTP_OK, json: true);
    }

    // ── Writes (OAuth scope-gated, acting as the token's user) ────────────

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function create(Request $request): JsonResponse
    {
        $bookcase = $this->mapper->fromCreatePayload($this->input->jsonBody($request));

        if ($violations = $this->input->violations($bookcase)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        $this->bookcaseService->create($bookcase);

        return new JsonResponse($this->bookcaseService->detailJson($bookcase), Response::HTTP_CREATED, json: true);
    }

    #[Route('/{bookcase}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function update(Request $request, Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $before = $this->bookcaseService->snapshot($bookcase);
        $this->mapper->applyPatch($bookcase, $this->input->jsonBody($request));

        if ($violations = $this->input->violations($bookcase)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        $this->bookcaseService->save($bookcase, $before, $user);

        return new JsonResponse($this->bookcaseService->detailJson($bookcase), Response::HTTP_OK, json: true);
    }

    #[Route('/{bookcase}/position', name: 'position', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function position(Request $request, Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $data = $this->input->jsonBody($request);
        $latitude = $this->input->coordinate($data['latitude'] ?? null, 90);
        $longitude = $this->input->coordinate($data['longitude'] ?? null, 180);

        $this->bookcaseService->move($bookcase, $latitude, $longitude, $user);

        return new JsonResponse([
            'id' => (string) $bookcase->id,
            'latitude' => $bookcase->position->latitude,
            'longitude' => $bookcase->position->longitude,
        ]);
    }

    #[Route('/{bookcase}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.DELETE')]
    public function delete(Request $request, Bookcase $bookcase, #[CurrentUser] User $user): JsonResponse
    {
        $reason = $this->input->string($this->input->jsonBody($request)['reason'] ?? null);
        if ($reason === null) {
            return new ApiProblem('A "reason" is required to delete an entry.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->bookcaseService->archiveAndDelete($bookcase, $reason, $user);

        return new JsonResponse(['status' => 'deleted']);
    }
}

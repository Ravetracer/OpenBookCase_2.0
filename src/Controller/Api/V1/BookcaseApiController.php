<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\DeletedBookcase;
use App\Entity\Embeddables\Address;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use App\Enums\MapSymbol;
use App\Repository\BookcaseRepository;
use App\Service\ShortCodeGenerator;

use Doctrine\ORM\EntityManagerInterface;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Public API v1 — bookcases.
 *
 * Reads are open (no token). Writes require an OAuth2 access token whose scope maps
 * to the role checked by #[IsGranted] (the bundle exposes scope `bookcases.write` as
 * role `ROLE_OAUTH2_BOOKCASES.WRITE`, etc.). Writes act as the token's user — exactly
 * the same `$this->getUser()` the website controllers use.
 *
 * JSON in, JSON out. The detail shape mirrors the website's single-bookcase endpoint
 * (same JMS groups); the bbox shape mirrors the map's marker payload.
 */
#[Route('/api/v1/bookcases', name: 'api_v1_bookcases_')]
class BookcaseApiController extends AbstractController
{
    private const DETAIL_GROUPS = ['bookcase', 'bookcase_detail', 'caretaker', 'address', 'images'];
    private const BBOX_MAX = 1000;

    public function __construct(
        private readonly BookcaseRepository $bookcases,
        private readonly EntityManagerInterface $entityManager,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ShortCodeGenerator $shortCodeGenerator,
    ) {
    }

    // ── Reads (open) ──────────────────────────────────────────────────────

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        foreach (['latMin', 'latMax', 'lonMin', 'lonMax'] as $p) {
            if ($request->query->get($p) === null) {
                return $this->problem('Missing bounding box parameter: ' . $p, Response::HTTP_BAD_REQUEST);
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

        $markers = [];
        foreach ($rows as $row) {
            $level = $row['accessibilityLevel'];
            if ($level !== null && !$level instanceof AccessibilityLevel) {
                $level = AccessibilityLevel::tryFrom((int) $level);
            }
            $entryType = $row['entryType'];
            $mapSymbol = $row['mapSymbol'];
            $status = $row['activeStatus'];

            $markers[] = [
                'id' => (string) $row['id'],
                'title' => $row['title'],
                'position' => ['latitude' => $row['latitude'], 'longitude' => $row['longitude']],
                'entryType' => $entryType instanceof EntryType ? $entryType->value : $entryType,
                'mapSymbol' => $mapSymbol instanceof MapSymbol ? $mapSymbol->value : $mapSymbol,
                'status' => $status instanceof ActiveStatus ? $status->value : $status,
                'accessibility' => $level instanceof AccessibilityLevel ? $level->markerColor() : null,
                'isMobile' => (bool) $row['isMobile'],
                'isBookcrossingZone' => (bool) $row['isBookcrossingZone'],
            ];
        }

        return new JsonResponse(['total' => $total, 'offset' => $offset, 'limit' => $limit, 'markers' => $markers]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'])]
    public function show(string $id): JsonResponse
    {
        $bookcase = $this->bookcases->findOneWithRelations($id);
        if ($bookcase === null) {
            return $this->problem('Bookcase not found.', Response::HTTP_NOT_FOUND);
        }

        return $this->detail($bookcase);
    }

    // ── Writes (OAuth scope-gated, acting as the token's user) ────────────

    #[Route('', name: 'create', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function create(Request $request): JsonResponse
    {
        $data = $this->jsonBody($request);

        $bookcase = new Bookcase();
        $bookcase->title = isset($data['title']) ? trim((string) $data['title']) : null;
        if (isset($data['entryType'])) {
            $entryType = EntryType::tryFrom((string) $data['entryType']);
            if ($entryType === null) {
                return $this->problem('Invalid entryType (expected "bookcase" or "givebox").', Response::HTTP_BAD_REQUEST);
            }
            $bookcase->entryType = $entryType;
        }
        if (isset($data['installationType'])) {
            $bookcase->installationType = trim((string) $data['installationType']);
        }
        $bookcase->isBookcrossingZone = (bool) ($data['isBookcrossingZone'] ?? false);
        $bookcase->position->latitude = isset($data['latitude']) ? (float) $data['latitude'] : null;
        $bookcase->position->longitude = isset($data['longitude']) ? (float) $data['longitude'] : null;

        if ($violations = $this->validationErrors($bookcase)) {
            return $this->problem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        $bookcase->shortCode = $this->shortCodeGenerator->unique();
        $this->entityManager->persist($bookcase);
        $this->entityManager->flush();

        return $this->detail($bookcase, Response::HTTP_CREATED);
    }

    #[Route('/{bookcase}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function update(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->jsonBody($request);

        if (array_key_exists('title', $data)) {
            $bookcase->title = trim((string) $data['title']);
            // The user has supplied a real title, so it's no longer the provisional
            // auto-generated one (clears the OSM "help name this bookcase" prompt) —
            // mirrors the website's full edit-form save.
            $bookcase->titleProvisional = false;
        }
        if (array_key_exists('webpage', $data)) {
            $bookcase->webpage = $this->nullableString($data['webpage']);
        }
        if (array_key_exists('comment', $data)) {
            $bookcase->comment = $this->nullableString($data['comment']);
        }
        if (array_key_exists('installationType', $data)) {
            $bookcase->installationType = $this->nullableString($data['installationType']);
        }
        if (array_key_exists('isMobile', $data)) {
            $bookcase->isMobile = (bool) $data['isMobile'];
        }
        if (array_key_exists('isBookcrossingZone', $data)) {
            $bookcase->isBookcrossingZone = (bool) $data['isBookcrossingZone'];
        }
        if (isset($data['entryType'])) {
            $entryType = EntryType::tryFrom((string) $data['entryType']);
            if ($entryType === null) {
                return $this->problem('Invalid entryType.', Response::HTTP_BAD_REQUEST);
            }
            $bookcase->entryType = $entryType;
        }
        if (isset($data['latitude'])) {
            $bookcase->position->latitude = (float) $data['latitude'];
        }
        if (isset($data['longitude'])) {
            $bookcase->position->longitude = (float) $data['longitude'];
        }
        if (array_key_exists('activeStatus', $data)) {
            $status = ActiveStatus::tryFrom((string) $data['activeStatus']);
            if ($status === null) {
                return $this->problem('Invalid activeStatus (expected "active" or "inactive").', Response::HTTP_BAD_REQUEST);
            }
            $bookcase->active->status = $status;
        }
        if (array_key_exists('statusDescription', $data)) {
            $bookcase->active->statusDescription = $this->nullableString($data['statusDescription']);
        }
        if (array_key_exists('accessibilityLevel', $data)) {
            if ($data['accessibilityLevel'] === null) {
                $bookcase->accessibility->level = null;
            } else {
                $level = AccessibilityLevel::tryFrom((int) $data['accessibilityLevel']);
                if ($level === null) {
                    return $this->problem('Invalid accessibilityLevel (expected 1, 2 or 3).', Response::HTTP_BAD_REQUEST);
                }
                $bookcase->accessibility->level = $level;
            }
        }
        if (array_key_exists('accessibilityDescription', $data)) {
            $bookcase->accessibility->description = $this->nullableString($data['accessibilityDescription']);
        }
        if (isset($data['address']) && is_array($data['address'])) {
            $this->applyAddress($bookcase, $data['address']);
        }

        if ($violations = $this->validationErrors($bookcase)) {
            return $this->problem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        $this->entityManager->flush();

        return $this->detail($bookcase);
    }

    #[Route('/{bookcase}/position', name: 'position', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function position(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->jsonBody($request);
        $lat = $data['latitude'] ?? null;
        $lon = $data['longitude'] ?? null;
        if (!is_numeric($lat) || !is_numeric($lon)
            || (float) $lat < -90 || (float) $lat > 90
            || (float) $lon < -180 || (float) $lon > 180) {
            return $this->problem('Invalid coordinates.', Response::HTTP_BAD_REQUEST);
        }

        $bookcase->position->latitude = (float) $lat;
        $bookcase->position->longitude = (float) $lon;
        $this->entityManager->flush();

        return new JsonResponse([
            'id' => (string) $bookcase->id,
            'latitude' => $bookcase->position->latitude,
            'longitude' => $bookcase->position->longitude,
        ]);
    }

    #[Route('/{bookcase}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.DELETE')]
    public function delete(Request $request, Bookcase $bookcase): JsonResponse
    {
        $reason = trim((string) ($this->jsonBody($request)['reason'] ?? ''));
        if ($reason === '') {
            return $this->problem('A "reason" is required to delete an entry.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $backup = new DeletedBookcase();
        $backup->originalId = (string) $bookcase->id;
        $backup->title = $bookcase->title;
        $backup->reason = $reason;
        $backup->deletedBy = $user->getUserIdentifier();
        $backup->payload = json_decode(
            $this->serializer->serialize($bookcase, 'json', SerializationContext::create()->setGroups(self::DETAIL_GROUPS)),
            true,
        ) ?? [];

        $this->entityManager->persist($backup);
        $this->entityManager->remove($bookcase);
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'deleted']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private function detail(Bookcase $bookcase, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse(
            $this->serializer->serialize($bookcase, 'json', SerializationContext::create()->setGroups(self::DETAIL_GROUPS)),
            $status,
            json: true,
        );
    }

    private function applyAddress(Bookcase $bookcase, array $address): void
    {
        $bookcase->address ??= new Address();
        foreach (['street', 'houseNumber', 'zipcode', 'city', 'additionalData'] as $field) {
            if (array_key_exists($field, $address)) {
                $bookcase->address->{$field} = $this->nullableString($address[$field]);
            }
        }
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            // Empty or invalid JSON body → treat as no fields (lets per-field
            // validation / required-field checks produce the right 4xx instead of
            // a blanket 400 from Symfony's RequestExceptionInterface).
            return [];
        }

        return is_array($data) ? $data : [];
    }

    private function nullableString(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    /** @return list<array{field:string, message:string}> */
    private function validationErrors(Bookcase $bookcase): array
    {
        $errors = [];
        foreach ($this->validator->validate($bookcase) as $violation) {
            $errors[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        return $errors;
    }

    /** @param list<array{field:string, message:string}> $errors */
    private function problem(string $message, int $status, array $errors = []): JsonResponse
    {
        $body = ['error' => $message];
        if ($errors) {
            $body['violations'] = $errors;
        }

        return new JsonResponse($body, $status);
    }
}

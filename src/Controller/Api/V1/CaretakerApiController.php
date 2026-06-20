<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\Embeddables\Address;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public API v1 — caretakers of a bookcase. Caretakers are intrinsic bookcase
 * data (who looks after the entry), so writing uses the `bookcases.write` scope
 * (role ROLE_OAUTH2_BOOKCASES.WRITE) — the same scope that edits the entry
 * itself. Reading is open.
 *
 * Caretakers are a many-to-many relation, but the API treats them as belonging
 * to the bookcase: POST creates one and attaches it; DELETE detaches it and, if
 * the caretaker is left looking after no bookcases at all, removes it entirely.
 */
#[Route('/api/v1/bookcases/{bookcase}/caretakers', name: 'api_v1_caretakers_')]
class CaretakerApiController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase): JsonResponse
    {
        $items = [];
        foreach ($bookcase->caretakers as $caretaker) {
            $items[] = $this->toArray($caretaker);
        }

        return new JsonResponse(['caretakers' => $items]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function add(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->jsonBody($request);

        $name = $this->nullableString($data['name'] ?? null);
        $contact = $this->nullableString($data['contact'] ?? null);
        if ($name === null && $contact === null) {
            return new JsonResponse(
                ['error' => 'A caretaker needs at least a "name" or "contact".'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $caretaker = new Caretaker();
        $caretaker->name = $name;
        $caretaker->contact = $contact;
        if (isset($data['address']) && is_array($data['address'])) {
            $this->applyAddress($caretaker, $data['address']);
        }
        $bookcase->addCaretaker($caretaker);

        $this->entityManager->persist($caretaker);
        $this->entityManager->flush();

        return new JsonResponse($this->toArray($caretaker), Response::HTTP_CREATED);
    }

    #[Route('/{caretaker}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function update(Request $request, Bookcase $bookcase, Caretaker $caretaker): JsonResponse
    {
        if (!$bookcase->caretakers->contains($caretaker)) {
            return new JsonResponse(['error' => 'Caretaker not found on this bookcase.'], Response::HTTP_NOT_FOUND);
        }

        $data = $this->jsonBody($request);
        if (array_key_exists('name', $data)) {
            $caretaker->name = $this->nullableString($data['name']);
        }
        if (array_key_exists('contact', $data)) {
            $caretaker->contact = $this->nullableString($data['contact']);
        }
        if (isset($data['address']) && is_array($data['address'])) {
            $this->applyAddress($caretaker, $data['address']);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->toArray($caretaker));
    }

    #[Route('/{caretaker}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function delete(Bookcase $bookcase, Caretaker $caretaker): JsonResponse
    {
        if (!$bookcase->caretakers->contains($caretaker)) {
            return new JsonResponse(['error' => 'Caretaker not found on this bookcase.'], Response::HTTP_NOT_FOUND);
        }

        // Is this the caretaker's only bookcase? The inverse `bookcases` collection
        // still reflects the (un-flushed) DB state, so check it BEFORE detaching:
        // if there are no *other* bookcases, the caretaker is about to be orphaned.
        $hasOtherBookcases = !$caretaker->bookcases
            ->filter(static fn (Bookcase $b): bool => $b->id?->equals($bookcase->id) !== true)
            ->isEmpty();

        $bookcase->removeCaretaker($caretaker);
        // A caretaker that no longer looks after any bookcase is orphaned data —
        // remove it rather than leave a dangling record.
        if (!$hasOtherBookcases) {
            $this->entityManager->remove($caretaker);
        }
        $this->entityManager->flush();

        return new JsonResponse(['status' => 'deleted']);
    }

    /** @return array<string, mixed> */
    private function toArray(Caretaker $caretaker): array
    {
        $address = $caretaker->address;

        return [
            'id' => (string) $caretaker->id,
            'name' => $caretaker->name,
            'contact' => $caretaker->contact,
            'address' => [
                'street' => $address?->street,
                'houseNumber' => $address?->houseNumber,
                'zipcode' => $address?->zipcode,
                'city' => $address?->city,
                'additionalData' => $address?->additionalData,
            ],
        ];
    }

    /** @param array<string, mixed> $address */
    private function applyAddress(Caretaker $caretaker, array $address): void
    {
        $caretaker->address ??= new Address();
        foreach (['street', 'houseNumber', 'zipcode', 'city', 'additionalData'] as $field) {
            if (array_key_exists($field, $address)) {
                $caretaker->address->{$field} = $this->nullableString($address[$field]);
            }
        }
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

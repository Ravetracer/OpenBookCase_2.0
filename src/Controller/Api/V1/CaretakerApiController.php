<?php

namespace App\Controller\Api\V1;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Http\ApiProblem;
use App\Service\ApiInput;
use App\Service\CaretakerService;
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
        private readonly ApiInput $input,
        private readonly CaretakerService $caretakers,
    ) {
    }

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Bookcase $bookcase): JsonResponse
    {
        $items = [];
        foreach ($bookcase->caretakers as $caretaker) {
            $items[] = $this->caretakers->toArray($caretaker);
        }

        return new JsonResponse(['caretakers' => $items]);
    }

    #[Route('', name: 'add', methods: ['POST'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function add(Request $request, Bookcase $bookcase): JsonResponse
    {
        $data = $this->input->jsonBody($request);

        $caretaker = new Caretaker();
        $caretaker->name = $this->input->string($data['name'] ?? null);
        $caretaker->contact = $this->input->string($data['contact'] ?? null);
        if ($caretaker->name === null && $caretaker->contact === null) {
            return new ApiProblem('A caretaker needs at least a "name" or "contact".', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($violations = $this->caretakers->create($bookcase, $caretaker, $data['address'] ?? null)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        return new JsonResponse($this->caretakers->toArray($caretaker), Response::HTTP_CREATED);
    }

    #[Route('/{caretaker}', name: 'update', methods: ['PATCH'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function update(Request $request, Bookcase $bookcase, Caretaker $caretaker): JsonResponse
    {
        if (!$bookcase->caretakers->contains($caretaker)) {
            return new ApiProblem('Caretaker not found on this bookcase.', Response::HTTP_NOT_FOUND);
        }

        $data = $this->input->jsonBody($request);
        if (array_key_exists('name', $data)) {
            $caretaker->name = $this->input->string($data['name']);
        }
        if (array_key_exists('contact', $data)) {
            $caretaker->contact = $this->input->string($data['contact']);
        }

        if ($violations = $this->caretakers->update($caretaker, $data['address'] ?? null)) {
            return new ApiProblem('Validation failed.', Response::HTTP_UNPROCESSABLE_ENTITY, $violations);
        }

        return new JsonResponse($this->caretakers->toArray($caretaker));
    }

    #[Route('/{caretaker}', name: 'delete', methods: ['DELETE'])]
    #[IsGranted('ROLE_OAUTH2_BOOKCASES.WRITE')]
    public function delete(Bookcase $bookcase, Caretaker $caretaker): JsonResponse
    {
        if (!$bookcase->caretakers->contains($caretaker)) {
            return new ApiProblem('Caretaker not found on this bookcase.', Response::HTTP_NOT_FOUND);
        }

        $this->caretakers->detach($bookcase, $caretaker);

        return new JsonResponse(['status' => 'deleted']);
    }
}

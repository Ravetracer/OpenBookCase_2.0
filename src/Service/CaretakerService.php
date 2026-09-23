<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\Caretaker;
use App\Entity\Embeddables\Address;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Caretakers of a bookcase for /api/v1: representation, validated create/update
 * and detach (removing a caretaker left looking after no bookcase at all).
 * Writes return the violations in the {@see \App\Http\ApiProblem} shape — empty
 * when the change was flushed, otherwise nothing is written.
 */
final class CaretakerService
{
    public function __construct(
        private readonly ApiInput $input,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(Caretaker $caretaker): array
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

    /**
     * Applies the optional JSON `address` object, validates and attaches the new
     * caretaker to the bookcase.
     *
     * @return list<array{field:string, message:string}>
     */
    public function create(Bookcase $bookcase, Caretaker $caretaker, mixed $address): array
    {
        if ($violations = $this->applyAndValidate($caretaker, $address)) {
            return $violations;
        }

        $bookcase->addCaretaker($caretaker);
        $this->entityManager->persist($caretaker);
        $this->entityManager->flush();

        return [];
    }

    /**
     * Applies the optional JSON `address` object (only the keys present) and
     * flushes the already-changed caretaker once it validates; on
     * violations its changes are reverted.
     *
     * @return list<array{field:string, message:string}>
     */
    public function update(Caretaker $caretaker, mixed $address): array
    {
        if ($violations = $this->applyAndValidate($caretaker, $address)) {
            // Discard the rejected changes so no later flush can write them.
            $this->entityManager->refresh($caretaker);

            return $violations;
        }

        $this->entityManager->flush();

        return [];
    }

    /** Detaches the caretaker; one that no longer looks after any bookcase is removed. */
    public function detach(Bookcase $bookcase, Caretaker $caretaker): void
    {
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
    }

    /** @return list<array{field:string, message:string}> */
    private function applyAndValidate(Caretaker $caretaker, mixed $address): array
    {
        if (is_array($address)) {
            $caretaker->address ??= new Address();
            foreach (['street', 'houseNumber', 'zipcode', 'city', 'additionalData'] as $field) {
                if (array_key_exists($field, $address)) {
                    $caretaker->address->{$field} = $this->input->string($address[$field]);
                }
            }
        }

        // Caretaker::$address carries no #[Assert\Valid], so the embeddable is validated on its own.
        $violations = $this->input->violations($caretaker);
        if ($caretaker->address !== null) {
            foreach ($this->input->violations($caretaker->address) as $violation) {
                $violations[] = ['field' => 'address.' . $violation['field'], 'message' => $violation['message']];
            }
        }

        return $violations;
    }
}

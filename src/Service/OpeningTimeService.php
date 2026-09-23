<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\OpeningTime;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Opening times of a bookcase for /api/v1: representation, validated
 * create/update and removal. Writes return the violations in the
 * {@see \App\Http\ApiProblem} shape — empty when the change was flushed,
 * otherwise nothing is written.
 */
final class OpeningTimeService
{
    public function __construct(
        private readonly ApiInput $input,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(OpeningTime $openingTime): array
    {
        return [
            'id' => (string) $openingTime->id,
            'openTime' => $openingTime->open_time,
            'twentyFourSeven' => (bool) $openingTime->twenty_for_seven,
        ];
    }

    /** @return list<array{field:string, message:string}> */
    public function create(Bookcase $bookcase, OpeningTime $openingTime): array
    {
        if ($violations = $this->input->violations($openingTime)) {
            return $violations;
        }

        $bookcase->addOpeningTime($openingTime);
        $this->entityManager->persist($openingTime);
        $this->entityManager->flush();

        return [];
    }

    /**
     * Flushes the already-changed opening time once it validates; on violations
     * its changes are reverted.
     *
     * @return list<array{field:string, message:string}>
     */
    public function update(OpeningTime $openingTime): array
    {
        if ($violations = $this->input->violations($openingTime)) {
            // Discard the rejected changes so no later flush can write them.
            $this->entityManager->refresh($openingTime);

            return $violations;
        }

        $this->entityManager->flush();

        return [];
    }

    public function delete(Bookcase $bookcase, OpeningTime $openingTime): void
    {
        // orphanRemoval on Bookcase::openingTimes deletes the row once detached.
        $bookcase->removeOpeningTime($openingTime);
        $this->entityManager->flush();
    }
}

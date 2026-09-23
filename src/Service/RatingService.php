<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\Rating;
use App\Entity\User;
use App\Repository\RatingRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Per-user bookcase ratings (one per user per bookcase) and their aggregate,
 * shared by the website's "Rate" popover and the public API's rating resource.
 */
class RatingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RatingRepository $ratingRepository,
    ) {
    }

    /**
     * Count, raw average and rounded average of a bookcase's ratings.
     *
     * @return array{count: int, average: int|float, rounded: int}
     */
    public function stats(Bookcase $bookcase): array
    {
        $values = array_map(static fn (Rating $r) => (int) $r->value, $bookcase->ratings->toArray());
        $count = count($values);
        $average = $count > 0 ? array_sum($values) / $count : 0.0;

        return [
            'count' => $count,
            'average' => $average,
            'rounded' => (int) round($average),
        ];
    }

    /**
     * {@see stats()} with the average rounded to two decimals, as published by the API.
     *
     * @return array{count: int, average: float, rounded: int}
     */
    public function publicStats(Bookcase $bookcase): array
    {
        $stats = $this->stats($bookcase);
        $stats['average'] = round($stats['average'], 2);

        return $stats;
    }

    /** The given user's rating value for the bookcase, or 0 when unrated / anonymous. */
    public function userValue(Bookcase $bookcase, ?User $user): int
    {
        if ($user === null) {
            return 0;
        }

        return $this->ratingRepository->findOneBy(['bookcase' => $bookcase, 'user' => $user])?->value ?? 0;
    }

    /**
     * Create or update the user's rating. The comment is only touched when
     * `$setComment` is true (the website has no comment field).
     */
    public function upsert(Bookcase $bookcase, User $user, int $value, bool $setComment = false, ?string $comment = null): Rating
    {
        $existing = $this->ratingRepository->findOneBy(['bookcase' => $bookcase, 'user' => $user]);
        $rating = $existing ?? new Rating();
        $rating->bookcase = $bookcase;
        $rating->user = $user;
        $rating->value = $value;
        if ($setComment) {
            $rating->comment = $comment;
        }

        $this->entityManager->persist($rating);
        $this->entityManager->flush();

        // Keep the in-memory ratings collection in sync. If it was already
        // initialized (empty) earlier in the request, a brand-new rating wouldn't
        // show up in it — so stats() would report a stale count/average on the
        // very first rating. addRating() is a no-op when it's already present.
        if ($existing === null) {
            $bookcase->addRating($rating);
        }

        return $rating;
    }

    /** Remove the user's rating of the bookcase, if any. */
    public function remove(Bookcase $bookcase, User $user): void
    {
        $existing = $this->ratingRepository->findOneBy(['bookcase' => $bookcase, 'user' => $user]);
        if ($existing !== null) {
            $bookcase->removeRating($existing);
            $this->entityManager->remove($existing);
            $this->entityManager->flush();
        }
    }
}

<?php declare(strict_types=1);

namespace App\Service;

use App\Repository\BookcaseRepository;

/**
 * Generates short, URL-safe codes for public share links (https://obc.onl/{code}).
 * Codes are random base62 (no look-alike-free constraints needed — the data is
 * public), kept short, and checked for uniqueness against existing bookcases.
 */
class ShortCodeGenerator
{
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    private const DEFAULT_LENGTH = 6;

    public function __construct(
        private readonly BookcaseRepository $bookcaseRepository,
    ) {
    }

    /** A random base62 code of the given length. */
    public function random(int $length = self::DEFAULT_LENGTH): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }

    /** A code that is unique in the DB (one query per attempt; for single new entries). */
    public function unique(): string
    {
        do {
            $code = $this->random();
        } while ($this->bookcaseRepository->findOneBy(['shortCode' => $code]) !== null);

        return $code;
    }

    /**
     * A code not present in the given in-memory set. For batch backfills that
     * pre-load every existing code, this avoids a DB query per row.
     *
     * @param array<string, true> $taken set of codes already used (by reference not needed)
     */
    public function randomUniqueIn(array $taken): string
    {
        do {
            $code = $this->random();
        } while (isset($taken[$code]));

        return $code;
    }
}

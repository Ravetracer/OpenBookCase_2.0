<?php declare(strict_types=1);

namespace App\Twig;

use App\Repository\BookcaseRepository;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Project-wide Twig helpers.
 */
class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly BookcaseRepository $bookcaseRepository,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('bookcase_count', $this->bookcaseCount(...)),
        ];
    }

    /** Total number of live bookcases — shown in the navbar. */
    public function bookcaseCount(): int
    {
        return $this->bookcaseRepository->count([]);
    }
}

<?php declare(strict_types=1);

namespace App\Twig;

use App\Repository\BookcaseRepository;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Project-wide Twig helpers.
 */
class AppExtension extends AbstractExtension
{
    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'tel'];

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

    public function getFilters(): array
    {
        return [
            new TwigFilter('external_url', $this->externalUrl(...)),
        ];
    }

    /** Total number of live bookcases — shown in the navbar. */
    public function bookcaseCount(): int
    {
        return $this->bookcaseRepository->count([]);
    }

    /**
     * Normalise a user-supplied website into an absolute URL so it links out
     * instead of being resolved relative to the current page.
     *
     * A value that already carries a scheme (`https://…`, `http://…`, `mailto:…`,
     * `tel:…`) is left untouched, any other scheme yields null (no link); a scheme-less value such as
     * `www.walding.at/Buecherinsel` gets an `https://` prefix.
     */
    public function externalUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '') {
            return $trimmed;
        }

        // Already has a URI scheme: keep only safe ones. Anything else (javascript:,
        // data:, vbscript:, …) is user-supplied script → no link at all (stored XSS).
        // A dot before the colon means host:port ("www.x.at:8080"), not a scheme.
        if (preg_match('#^([a-z][a-z0-9+\-]*):#i', $trimmed, $m) === 1) {
            return in_array(strtolower($m[1]), self::SAFE_SCHEMES, true) ? $trimmed : null;
        }

        // Protocol-relative "//host/path".
        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return 'https://' . $trimmed;
    }
}

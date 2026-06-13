<?php

namespace App\Service;

/**
 * Determines whether a free-text opening-hours string means "24 hours, 7 days a week".
 *
 * Strategy:
 *   1. Fast exact-match lookup against a normalised (lowercase + trimmed) list.
 *   2. Regex fallback for variants with extra context text or alternate formatting.
 *
 * Regex design notes:
 *   - Patterns that match a time range (e.g. 00:00–24:00) use negative lookbehinds
 *     to prevent false positives where the "00" is the *minutes* part of a non-midnight
 *     start time (e.g. "05:00 - 24:00" must NOT match).
 *   - The `24h / 24 std / 24 stunden` pattern is scoped to word boundaries so that
 *     "24:00" (a clock value) is not mistaken for a standalone duration.
 */
class TwentyFourSevenDetector
{
    // All strings already lowercased so mb_strtolower() of the input matches directly.
    private const EXACT_MATCHES = [
        '24 / 7', '24/7', 'immer', '24 std. 7 tage',
        '00:00 - 24:00 uhr', '00:00-24:00', '00-24 uhr', '00:00 - 24:00', '0-24', '24 h / 7', 'always', '7x24',
        '24h/dag alle dagen', '24/7/365', 'alle dagen', 'durchgägngig geöffnet', 'круглосуточно: 24 часа',
        'круглосуточно', '24h', '24 hours', 'durchgehend', 'frei zugänglich', 'jederzeit', '24 / 24',
        '24 stunden geöffnet', '24 /7', '24h offen', 'rund um die uhr', '24', '24 h zugänglich', '24 часа', '24 uur',
        'always open', 'altijd', '24 uur per dag', 'круглосуточно, круглогодично', '24 std', '27 / 7', '24/24',
        'allways open', '24-7', '24u', 'alle dagen te bezoeken', '00:00-23:59', '24|365', '24 hours a day, 7 days a week',
        '24 h / 7 tage', '24 stunden frei zugänglich', '0-24 uhr', '7/24', '24/ 7', 'tag und nacht', 'all day',
        '00.00-24.00 uur', '24 std./7 tage', '24 h an 7 tagen', '7 / 24', 'ganztägig 24/7', '24 h',
        '00:00 - 23:59', 'unbegrenzt', '24 std. / 7 tage', '27/7', '24h open acess', '0.00-24.00',
        '00:00 - 24.00 uhr', 'ganztägig', 'altijd open', '24/12', '0-24 h', 'jederzeit frei zugänglich', 'jederzeit offen',
        '24 hours a day 7 days a week', '14/7', '24:7', '24/7 - 7/7', 'immer offen', '24  / 7', '24/7 - jederzeit',
        'durchgehend geöffnet - open 24/7', 'alwyas open', 'immer geöffnet', '24 stunden, 7 tage / woche', '0.00 - 24:00',
        '24/7 open', 'open 24 hours', '24h / 7 tage', '24 stunden, 7 tage in der woche', 'ganzjährlich', '24 stunden rund um die uhr',
        'rund um die uhr geöffnet', '0:00-23:59', 'altijd toegankelijk', '24h/7d', '00.00 - 24.00',
        'open end', '24 h / 7 d', 'täglich, rund um die uhr', 'durchgehend geöffnet', '0.00 bis 24.00',
        'öffentlich, immer offen', '0:00-24:00', 'durchgängig', '00.00 - 24.00 uhr', 'ganzjährig offen', 'dauerhaft zugänglich',
        'unverschlossen', 'ständig', '0 - 24 uhr', '24 stunden täglich', '24/7 ganzjährig', '0.00 - 24.00 uhr', '24x7',
        'offen zugänglich', 'von 0 bis 24 uhr', 'ganztags', 'keine', 'öffentlich', '24h geöffnet', '7/7', '00.00 bis 24.00',
        'ganzjährig', '00:00 bis 24:00', 'immer erreichbar', '00000-24.00', '00.00-24-00', '00:00 uhr - 24:00 uhr',
        '24h/7t', '24 std.', '24h täglich', 'permanent', '24 stunden/ 7 tage die woche', '24\\7', '24/07', '24 std geöffnet',
        '24 h geöffnet', 'allzeit', 'immer öffentlich zugänglich', 'durchgehend geöffnet 24/7', 'täglich 24 stunden',
        'rund um die uhr öffentlich zugänglich', 'durchgängig geöffnet', 'permanent zugänglich', '7 x 24 h',
        'immer!', '24h/7', '24h 7tage', '24h 7 tage', '00. uhr bis 24. uhr', '24/7, rund um die uhr', '24 stunden tag & nacht',
        '24/', 'immer geöffnet!', 'jederzeit zugänglich', '24 stunden / 7 tage', '24 stunden am tag', 'öffentlich',
        'dauerhaft', 'zu jeder zeit', 'durchgängig zugänglich', '00:00  - 24:00 uhr', '00.00 uhr bis 00.00 uhr',
        'ständig geöffnet', '24/7 - offen zugänglich', 'geöffnet 24/7', 'öffentlich zugänglich', '25/7', 'mo-so 24 h',
        'durchgehend offen', 'durchgehend/permanently', '24h/ 7 taden', '24/24 h', '24/24 7/7', 'toujours', '24h / 7 taden',
        '24 std./tag', '00-24', '00 -24 uhr', '00:00 -24:00 uhr', 'immer frei zugänglich', '24-stunden', '0/24',
        '7 tage / 24 stunden',
        // Additional entries found in the PHPMyAdmin export
        '0-24h', '0.00 - 24:00', '00.00  - 24:00 uhr', '00:00 - 24:00 uur', '00:00-24:00 h', '00:00-24:00 uhr',
        '0:00 - 24:00', '0:00 - 24:00 uhr', '24 stunden', '24/7 (im freien)',
    ];

    /**
     * Each pattern is applied to the mb_strtolower()ed and trimmed input.
     *
     * Pattern 1 – full-clock time range (00:00 – 24:00 or 00:00 – 23:59):
     *   (?<!\d)          prevents matching the "00" *minutes* part of e.g. "05:00"
     *   0{1,2}[.:]       requires a separator after the hour digits → real "0:00" / "00:00"
     *   \s*0{1,2}        minutes part (00)
     *   \s*[-–]\s*       separator between start/end
     *   (?:24|23[.:]\s*5[89])  end is 24:xx or 23:59/23:58
     *
     * Pattern 2 – shorthand "0-24" without colon notation:
     *   (?<![.:,\d])     prevents matching "00" preceded by a colon (i.e. minutes)
     *   (?!\d)           prevents matching "240" etc.
     *
     * Patterns 3-7 – unambiguous keywords / fractions.
     */
    private const PATTERNS = [
        // Full-clock midnight-to-midnight range: 0:00–24:00 / 00.00–23:59 etc.
        '/(?<!\d)0{1,2}[.:]\s*0{1,2}\s*[-–]\s*(?:24|23[.:]\s*5[89])(?:[.:h]?\d{0,2})?\b/u',
        // Shorthand: "0-24", "0 - 24 h", "0-24h" (not preceded by colon/dot/comma/digit)
        '/(?<![.:,\d])0+\s*[-–]\s*24\s*(?:uhr|h|uur)?(?!\d)/u',
        // 24/7, 7/24, 24x7, 24|7 etc.
        '/\b(?:24\s*[\/|x]\s*7|7\s*[\/x]\s*24)\b/ui',
        // "24 h", "24 std", "24 stunden", "24 hours" as standalone duration
        '/\b24\s*(?:h|std\.?|stunden?|hours?)\b/ui',
        // German "round the clock"
        '/rund\s+um\s+die\s+uhr/u',
        // Russian "round the clock"
        '/круглосуточно/u',
        // German Mon–Sun shorthand: "mo-so 24 h", "mo so 24"
        '/\bmo[-.\s]*so\s+24\b/u',
    ];

    public function detect(string $raw): bool
    {
        $normalized = mb_strtolower(trim($raw), 'UTF-8');

        if ($normalized === '' || $normalized === '-' || $normalized === '.') {
            return false;
        }

        if (in_array($normalized, self::EXACT_MATCHES, true)) {
            return true;
        }

        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $normalized) === 1) {
                return true;
            }
        }

        return false;
    }
}

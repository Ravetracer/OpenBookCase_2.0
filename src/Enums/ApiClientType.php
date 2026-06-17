<?php declare(strict_types=1);

namespace App\Enums;

/**
 * OAuth client type requested by an API applicant.
 *
 * - PublicClient: mobile/SPA apps that cannot keep a secret → Authorization Code
 *   + PKCE, no client secret (the secure standard for such apps).
 * - Confidential: server-side apps that can store a secret → a client secret is
 *   issued (shown once) on approval.
 *
 * (Case name is `PublicClient` because `public` is a reserved word.)
 */
enum ApiClientType: string
{
    case PublicClient = 'public';
    case Confidential = 'confidential';

    public function usesSecret(): bool
    {
        return $this === self::Confidential;
    }
}

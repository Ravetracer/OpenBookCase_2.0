<?php declare(strict_types=1);

namespace App\Enums;

/**
 * Lifecycle of a developer's application for API access. The admin moves it
 * Pending → Approved / Denied; an approved application can later be Revoked.
 */
enum ApiApplicationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Denied = 'denied';
    case Revoked = 'revoked';

    /** DaisyUI badge modifier for the status pill (literal classes, no safelist needed). */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'badge-warning',
            self::Approved => 'badge-success',
            self::Denied => 'badge-error',
            self::Revoked => 'badge-ghost',
        };
    }

    /** Whether the applicant may still act on the application (reply, withdraw). */
    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}

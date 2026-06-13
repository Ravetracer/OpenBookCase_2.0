<?php declare(strict_types=1);

namespace App\Enums;

/**
 * How a user wants to receive system notifications. A per-user profile setting,
 * honoured by App\Service\MessageService.
 */
enum NotificationChannel: string
{
    case Internal = 'internal';
    case Email = 'email';
    case Both = 'both';

    /** Store the notification in the on-page inbox. */
    public function deliversInternal(): bool
    {
        return $this === self::Internal || $this === self::Both;
    }

    /** Send the notification by e-mail. */
    public function deliversEmail(): bool
    {
        return $this === self::Email || $this === self::Both;
    }
}

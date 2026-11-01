<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\User;

use Monolog\Attribute\WithMonologChannel;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Invisible, captcha-free bot defence for the sign-up form. Three layers:
 *  - Honeypot: a "website" field that humans never see (off-screen, aria-hidden,
 *    not focusable). A bot that fills every input trips it and is silently dropped.
 *  - Time trap: the form carries an HMAC-signed render timestamp. A submission
 *    sooner than the minimum fill time (scripted POST) or with a missing, forged or
 *    stale stamp is rejected with a validation error, so a human can just resubmit.
 *  - Per-IP rate limit on accepted sign-ups (IPv6 by /64).
 * Every trip is logged to the "bot_trap" channel.
 */
#[WithMonologChannel('bot_trap')]
class RegistrationBotGuard
{
    public const HONEYPOT_FIELD = 'website';
    public const STAMP_FIELD = 'formStamp';

    /** A form older than this (e.g. a tab left open for days) must be reloaded. */
    public const MAX_AGE_SECONDS = 86400;

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly LoggerInterface $logger,
        #[Target('registration')]
        private readonly RateLimiterFactoryInterface $registrationLimiter,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
        #[Autowire('%app.registration.min_fill_seconds%')]
        private readonly int $minFillSeconds,
    ) {
    }

    /** A fresh signed stamp of the current time, rendered into the form. */
    public function issueStamp(): string
    {
        $timestamp = (string) $this->clock->now()->getTimestamp();

        return $timestamp . '.' . $this->sign($timestamp);
    }

    /**
     * Validation message key (validators domain) when the submitted stamp is
     * missing, forged, too fresh or too old; null when it is acceptable.
     */
    public function stampError(mixed $stamp): ?string
    {
        if (!\is_string($stamp) || !preg_match('/^(\d{1,12})\.([0-9a-f]{64})\z/', $stamp, $m) || !hash_equals($this->sign($m[1]), $m[2])) {
            $this->log('invalid_stamp');

            return 'registration.form_expired';
        }

        $age = $this->clock->now()->getTimestamp() - (int) $m[1];
        if ($age < $this->minFillSeconds) {
            $this->log('too_fast', ['seconds' => $age]);

            return 'registration.too_fast';
        }
        if ($age > self::MAX_AGE_SECONDS) {
            $this->log('expired', ['seconds' => $age]);

            return 'registration.form_expired';
        }

        return null;
    }

    /**
     * Whether a sign-up that passed validation may create an account. Consumes one
     * sign-up from the client's budget either way; returns false when over the limit.
     */
    public function consumeSignUp(?string $clientIp): bool
    {
        $ip = (string) $clientIp;
        $key = str_contains($ip, ':') ? IpUtils::anonymize($ip, 0, 8) : $ip;

        if ($this->registrationLimiter->create('ip:' . $key)->consume(1)->isAccepted()) {
            return true;
        }
        $this->log('rate_limited');

        return false;
    }

    /** True when the hidden honeypot field was filled in, i.e. the sender is a bot. */
    public function isHoneypotFilled(mixed $value, User $user): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        // Log the e-mail domain only: the address usually belongs to an uninvolved person.
        $email = (string) $user->email;
        $this->log('honeypot', [
            'username' => $user->username,
            'email_domain' => substr($email, (int) strrpos($email, '@') + 1),
        ]);

        return true;
    }

    private function sign(string $timestamp): string
    {
        return hash_hmac('sha256', 'registration|' . $timestamp, $this->secret);
    }

    /** @param array<string, mixed> $context */
    private function log(string $reason, array $context = []): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $this->logger->notice('Registration blocked: {reason}', $context + [
            'reason' => $reason,
            'ip' => $request?->getClientIp(),
            'user_agent' => $request?->headers->get('User-Agent'),
        ]);
    }
}

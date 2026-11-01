<?php declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Service\RegistrationBotGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

final class RegistrationBotGuardTest extends TestCase
{
    private MockClock $clock;

    /** @var list<array{string, array<string, mixed>}> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-01 12:00:00');
        $this->logged = [];
    }

    public function testFreshStampIsAcceptedAfterTheMinimumFillTime(): void
    {
        $guard = $this->guard();
        $stamp = $guard->issueStamp();

        $this->clock->sleep(3);

        $this->assertNull($guard->stampError($stamp));
        $this->assertSame([], $this->logged);
    }

    public function testSubmissionFasterThanTheMinimumFillTimeIsRejected(): void
    {
        $guard = $this->guard();
        $stamp = $guard->issueStamp();

        $this->clock->sleep(2);

        $this->assertSame('registration.too_fast', $guard->stampError($stamp));
        $this->assertSame('too_fast', $this->logged[0][1]['reason']);
        $this->assertSame(2, $this->logged[0][1]['seconds']);
    }

    public function testStaleStampIsRejected(): void
    {
        $guard = $this->guard();
        $stamp = $guard->issueStamp();

        $this->clock->sleep(RegistrationBotGuard::MAX_AGE_SECONDS);
        $this->assertNull($guard->stampError($stamp), 'exactly the maximum age is still fine');

        $this->clock->sleep(1);
        $this->assertSame('registration.form_expired', $guard->stampError($stamp));
        $this->assertSame('expired', $this->logged[0][1]['reason']);
    }

    public function testStampSignedWithAnotherSecretIsRejected(): void
    {
        $stamp = $this->guard(secret: 'other-secret')->issueStamp();
        $this->clock->sleep(10);

        $this->assertSame('registration.form_expired', $this->guard()->stampError($stamp));
        $this->assertSame('invalid_stamp', $this->logged[0][1]['reason']);
    }

    public function testBackdatedStampWithReusedSignatureIsRejected(): void
    {
        $guard = $this->guard();
        [$timestamp, $signature] = explode('.', $guard->issueStamp());

        // Moving the timestamp back (to skip the wait) invalidates the signature.
        $this->assertSame('registration.form_expired', $guard->stampError(((int) $timestamp - 60) . '.' . $signature));
    }

    #[DataProvider('malformedStamps')]
    public function testMalformedStampIsRejected(mixed $stamp): void
    {
        $this->assertSame('registration.form_expired', $this->guard()->stampError($stamp));
    }

    public static function malformedStamps(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
        yield 'array' => [['1700000000.abc']];
        yield 'integer' => [1700000000];
        yield 'timestamp only' => ['1700000000'];
        yield 'no timestamp' => ['.' . str_repeat('a', 64)];
        yield 'short signature' => ['1700000000.abcdef'];
        yield 'non-hex signature' => ['1700000000.' . str_repeat('z', 64)];
        yield 'negative timestamp' => ['-1.' . str_repeat('a', 64)];
        yield 'trailing newline' => ["1700000000." . str_repeat('a', 64) . "\n"];
        yield 'huge' => [str_repeat('9', 100000)];
    }

    public function testEmptyHoneypotPasses(): void
    {
        $guard = $this->guard();

        $this->assertFalse($guard->isHoneypotFilled(null, $this->user()));
        $this->assertFalse($guard->isHoneypotFilled('', $this->user()));
        $this->assertSame([], $this->logged);
    }

    public function testFilledHoneypotIsDetectedAndLoggedWithoutTheFullAddress(): void
    {
        $this->assertTrue($this->guard()->isHoneypotFilled('https://spam.example', $this->user()));

        $context = $this->logged[0][1];
        $this->assertSame('honeypot', $context['reason']);
        $this->assertSame('botname', $context['username']);
        $this->assertSame('example.org', $context['email_domain']);
        $this->assertSame('203.0.113.7', $context['ip']);
        $this->assertStringNotContainsString('victim', json_encode($context, \JSON_THROW_ON_ERROR));
    }

    public function testSignUpsArePerIpRateLimited(): void
    {
        $guard = $this->guard();

        for ($i = 0; $i < 5; ++$i) {
            $this->assertTrue($guard->consumeSignUp('203.0.113.7'), "sign-up #$i is within the budget");
        }
        $this->assertFalse($guard->consumeSignUp('203.0.113.7'));
        $this->assertSame('rate_limited', $this->logged[0][1]['reason']);

        $this->assertTrue($guard->consumeSignUp('198.51.100.1'), 'another IP has its own budget');
    }

    public function testIpv6SignUpsShareTheBudgetOfTheirSlash64(): void
    {
        $guard = $this->guard();

        for ($i = 1; $i <= 5; ++$i) {
            $this->assertTrue($guard->consumeSignUp("2001:db8:1:2::$i"));
        }
        $this->assertFalse($guard->consumeSignUp('2001:db8:1:2:ffff::1'), 'same /64, rotated interface id');
        $this->assertTrue($guard->consumeSignUp('2001:db8:1:3::1'), 'a different /64');
    }

    private function guard(string $secret = 'test-secret'): RegistrationBotGuard
    {
        $requestStack = new RequestStack();
        $requestStack->push(Request::create('/register', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

        $logger = new class($this->logged) extends AbstractLogger {
            /** @param list<array{string, array<string, mixed>}> $logged */
            public function __construct(private array &$logged)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logged[] = [(string) $message, $context];
            }
        };

        $limiter = new RateLimiterFactory(
            ['id' => 'registration', 'policy' => 'sliding_window', 'limit' => 5, 'interval' => '1 hour'],
            new InMemoryStorage(),
        );

        return new RegistrationBotGuard($this->clock, $requestStack, $logger, $limiter, $secret, 3);
    }

    private function user(): User
    {
        $user = new User();
        $user->username = 'botname';
        $user->email = 'victim@example.org';

        return $user;
    }
}

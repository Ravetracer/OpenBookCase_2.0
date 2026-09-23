<?php declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Enums\ApiApplicationStatus;
use App\Service\ApiApplicationService;
use App\Tests\Factory\ApiApplicationFactory;
use App\Tests\Factory\UserFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * ApiApplicationService's form-facing entry points (submit / reply / secret ack),
 * which report a rejected request as an HttpException(status, translation key).
 */
final class ApiApplicationSubmitTest extends KernelTestCase
{
    /** @return iterable<string, array{string}> */
    public static function dangerousRedirectUris(): iterable
    {
        yield 'javascript' => ['javascript:alert(document.cookie)'];
        yield 'javascript, upper case' => ['JavaScript:alert(1)'];
        yield 'javascript, tab inside the scheme' => ["java\tscript:alert(1)"];
        yield 'data' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='];
        yield 'vbscript' => ['vbscript:msgbox(1)'];
        yield 'file' => ['file:///etc/passwd'];
    }

    /** @return iterable<string, array{string}> */
    public static function legitimateRedirectUris(): iterable
    {
        yield 'https' => ['https://example.com/callback'];
        yield 'http localhost' => ['http://localhost:8080/callback'];
        yield 'reverse-domain app scheme' => ['com.example.app:/callback'];
        yield 'custom app scheme' => ['myapp://callback'];
    }

    #[DataProvider('dangerousRedirectUris')]
    public function testSubmitRejectsDangerousRedirectScheme(string $uri): void
    {
        $user = UserFactory::createOne();

        try {
            $this->service()->submit($user, $this->form("https://example.com/cb\n" . $uri));
            $this->fail('a dangerous redirect URI must be rejected');
        } catch (HttpException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('flash.api_invalid_application', $e->getMessage());
        }
    }

    #[DataProvider('legitimateRedirectUris')]
    public function testSubmitAcceptsLegitimateRedirectUri(string $uri): void
    {
        UserFactory::new()->admin()->create();
        $user = UserFactory::createOne();

        $application = $this->service()->submit($user, $this->form($uri));

        $this->assertSame(ApiApplicationStatus::Pending, $application->status);
        $this->assertSame([$uri], $application->redirectUris);
    }

    public function testSubmitConflictsWhileAnApplicationIsOpen(): void
    {
        $user = UserFactory::createOne();
        ApiApplicationFactory::createOne(['applicant' => $user, 'status' => ApiApplicationStatus::Pending]);

        $this->expectExceptionObject(new HttpException(409, 'flash.api_already_pending'));
        $this->service()->submit($user, $this->form('https://example.com/cb'));
    }

    public function testOnlyTheApplicantMayReply(): void
    {
        $application = ApiApplicationFactory::createOne(['status' => ApiApplicationStatus::Pending]);

        try {
            $this->service()->replyAsApplicant($application, UserFactory::createOne(), 'Hello');
            $this->fail('a stranger must not reply');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function testReplyToClosedApplicationConflicts(): void
    {
        $application = ApiApplicationFactory::createOne(['status' => ApiApplicationStatus::Denied]);

        $this->expectExceptionObject(new HttpException(409, 'flash.api_reply_closed'));
        $this->service()->replyAsApplicant($application, $application->applicant, 'Hello');
    }

    private function service(): ApiApplicationService
    {
        return self::getContainer()->get(ApiApplicationService::class);
    }

    private function form(string $redirectUris): InputBag
    {
        return new InputBag([
            'appName' => 'Reader',
            'useCase' => 'A mobile app that shows nearby bookcases to my users.',
            'clientType' => 'public',
            'scopes' => ['bookcases.write'],
            'redirectUris' => $redirectUris,
        ]);
    }
}

<?php declare(strict_types=1);

namespace App\Tests\Unit\EventSubscriber;

use App\Entity\ApiApplication;
use App\Entity\ApiUsageLog;
use App\EventSubscriber\ApiTelemetrySubscriber;
use App\Repository\ApiApplicationRepository;
use Doctrine\ORM\EntityManagerInterface;
use League\Bundle\OAuth2ServerBundle\Security\Authentication\Token\OAuth2Token;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit-tests the API telemetry subscriber in isolation: it always persists a usage
 * log for an OAuth-tokened request, but only pings Matomo when running in prod.
 */
final class ApiTelemetrySubscriberTest extends TestCase
{
    private function tokenStorage(?OAuth2Token $token): TokenStorage
    {
        $storage = new TokenStorage();
        if ($token !== null) {
            $storage->setToken($token);
        }

        return $storage;
    }

    private function writeRequest(): Request
    {
        $request = Request::create(
            '/api/v1/bookcases',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"title":"X"}',
        );
        $request->attributes->set('_route', 'api_v1_bookcases_create');

        return $request;
    }

    private function dispatch(ApiTelemetrySubscriber $sub, Request $request, Response $response): void
    {
        $kernel = $this->createStub(HttpKernelInterface::class);
        $sub->onResponse(new ResponseEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST, $response));
        $sub->onTerminate(new TerminateEvent($kernel, $request, $response));
    }

    public function testPersistsLogAndPingsMatomoInProd(): void
    {
        $app = new ApiApplication();
        $app->appName = 'Telemetry App';

        $repo = $this->createStub(ApiApplicationRepository::class);
        $repo->method('findOneBy')->willReturn($app);

        $persisted = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            static function (object $e) use (&$persisted): void { $persisted[] = $e; },
        );
        $em->expects(self::once())->method('flush');

        $captured = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            // HttpClient encodes an array body to a form-urlencoded string before the callback.
            $body = $options['body'] ?? '';
            $parsed = [];
            if (is_string($body)) {
                parse_str($body, $parsed);
            }
            $captured[] = ['method' => $method, 'url' => $url, 'body' => $parsed];

            return new MockResponse();
        });

        $token = new OAuth2Token(null, 'tok-1', 'client_abc', ['bookcases.write'], 'ROLE_OAUTH2_');
        $sub = new ApiTelemetrySubscriber(
            $this->tokenStorage($token), $em, $repo, $http,
            'https://matomo.example/matomo.php', 1, 'prod',
        );

        $this->dispatch($sub, $this->writeRequest(), new Response('', 201));

        self::assertCount(1, $persisted);
        self::assertInstanceOf(ApiUsageLog::class, $persisted[0]);
        self::assertSame('api_v1_bookcases_create', $persisted[0]->routeName);
        self::assertSame(201, $persisted[0]->statusCode);
        self::assertSame(['title' => 'X'], $persisted[0]->requestPayload);

        self::assertCount(1, $captured, 'Matomo must be pinged in prod.');
        self::assertSame('https://matomo.example/matomo.php', $captured[0]['url']);
        self::assertSame('API / api_v1_bookcases_create', $captured[0]['body']['action_name']);
        self::assertSame('Telemetry App', $captured[0]['body']['dimension1']);
    }

    public function testDoesNotPingMatomoOutsideProd(): void
    {
        $repo = $this->createStub(ApiApplicationRepository::class);
        $repo->method('findOneBy')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $captured = [];
        $http = new MockHttpClient(function () use (&$captured): MockResponse {
            $captured[] = true;

            return new MockResponse();
        });

        $token = new OAuth2Token(null, 'tok-1', 'client_abc', ['bookcases.write'], 'ROLE_OAUTH2_');
        $sub = new ApiTelemetrySubscriber(
            $this->tokenStorage($token), $em, $repo, $http,
            'https://matomo.example/matomo.php', 1, 'test',
        );

        $this->dispatch($sub, $this->writeRequest(), new Response('', 201));

        self::assertCount(0, $captured, 'Matomo must not be pinged outside prod.');
    }

    public function testIgnoresAnonymousRequests(): void
    {
        $repo = $this->createStub(ApiApplicationRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse());

        $sub = new ApiTelemetrySubscriber(
            $this->tokenStorage(null), $em, $repo, $http,
            'https://matomo.example/matomo.php', 1, 'prod',
        );

        // No OAuth token in storage → nothing recorded.
        $this->dispatch($sub, $this->writeRequest(), new Response('', 200));

        $this->addToAssertionCount(1);
    }
}

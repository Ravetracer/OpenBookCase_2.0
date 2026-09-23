<?php declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Keeps the documented /api/v1 error contract (`{"error": "..."}` JSON) for HTTP
 * exceptions thrown outside the controllers' own responses — e.g. a 400 for a
 * wrongly-typed field, or a 404 when an id in the URL doesn't resolve.
 * Server errors (500) are left to Symfony's default handling.
 */
#[AsEventListener(event: 'kernel.exception')]
final class ApiExceptionSubscriber
{
    public function __invoke(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof HttpExceptionInterface || !str_starts_with($event->getRequest()->getPathInfo(), '/api/v1/')) {
            return;
        }

        $status = $exception->getStatusCode();
        // Only 400s carry our own input-validation messages; others (e.g. the entity
        // resolver's 404) would leak internals, so they get the plain status text.
        $message = $status === 400 && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : (JsonResponse::$statusTexts[$status] ?? 'Error');

        $event->setResponse(new JsonResponse(
            ['error' => $message],
            $status,
            $exception->getHeaders(),
        ));
    }
}

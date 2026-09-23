<?php declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * The documented /api/v1 error body: `{"error": "…"}`, plus a `violations` list
 * (`[{field, message}]`) for 422 validation failures.
 */
final class ApiProblem extends JsonResponse
{
    /** @param list<array{field:string, message:string}> $violations */
    public function __construct(string $message, int $status, array $violations = [])
    {
        $body = ['error' => $message];
        if ($violations !== []) {
            $body['violations'] = $violations;
        }

        parent::__construct($body, $status);
    }
}

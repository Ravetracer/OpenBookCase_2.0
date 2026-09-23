<?php declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Typed readers for /api/v1 JSON bodies. Wrongly-typed values (arrays/objects
 * where a string or number is expected) are rejected with a 400 instead of being
 * cast — PHP would otherwise store them as the literal "Array" or turn them into
 * 0 / 1. The 400 is rendered as the documented `{"error": …}` body by
 * {@see \App\EventSubscriber\ApiExceptionSubscriber}.
 */
final class ApiInput
{
    public function __construct(private readonly ValidatorInterface $validator)
    {
    }

    /** @return array<string, mixed> */
    public function jsonBody(Request $request): array
    {
        try {
            $data = $request->toArray();
        } catch (\Throwable) {
            // Empty or invalid JSON body → treat as no fields (lets per-field
            // validation / required-field checks produce the right 4xx instead of
            // a blanket 400 from Symfony's RequestExceptionInterface).
            return [];
        }

        return is_array($data) ? $data : [];
    }

    /** Trimmed string, or null for null / blank. */
    public function string(mixed $value): ?string
    {
        if ($value !== null && !is_scalar($value)) {
            throw new BadRequestHttpException('Expected a string value.');
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** A finite coordinate within ±$limit (90 for latitude, 180 for longitude). */
    public function coordinate(mixed $value, float $limit): float
    {
        if (!is_numeric($value) || !is_finite((float) $value) || abs((float) $value) > $limit) {
            throw new BadRequestHttpException('Invalid coordinates.');
        }

        return (float) $value;
    }

    /**
     * Validation errors of an entity in the `violations` shape of {@see \App\Http\ApiProblem}.
     *
     * @return list<array{field:string, message:string}>
     */
    public function violations(object $entity): array
    {
        $errors = [];
        foreach ($this->validator->validate($entity) as $violation) {
            $errors[] = ['field' => $violation->getPropertyPath(), 'message' => (string) $violation->getMessage()];
        }

        return $errors;
    }
}

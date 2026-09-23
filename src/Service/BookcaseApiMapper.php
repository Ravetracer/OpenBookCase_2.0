<?php declare(strict_types=1);

namespace App\Service;

use App\Entity\Bookcase;
use App\Entity\Embeddables\Address;
use App\Enums\AccessibilityLevel;
use App\Enums\ActiveStatus;
use App\Enums\EntryType;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Maps /api/v1 bookcase JSON bodies onto the entity. All values go through
 * {@see ApiInput}; an invalid enum value is rejected with a 400 (rendered as the
 * documented `{"error": …}` body by the API exception subscriber). Entity
 * validation is left to the caller.
 */
class BookcaseApiMapper
{
    private const ADDRESS_FIELDS = ['street', 'houseNumber', 'zipcode', 'city', 'additionalData'];

    public function __construct(private readonly ApiInput $input)
    {
    }

    /**
     * A new, unsaved bookcase from a create body.
     *
     * @param array<string, mixed> $data
     */
    public function fromCreatePayload(array $data): Bookcase
    {
        $bookcase = new Bookcase();
        $bookcase->title = $this->input->string($data['title'] ?? null);
        if (isset($data['entryType'])) {
            $bookcase->entryType = $this->entryType($data['entryType'], 'Invalid entryType (expected "bookcase" or "givebox").');
        }
        if (isset($data['installationType'])) {
            $bookcase->installationType = $this->input->string($data['installationType']);
        }
        $bookcase->isBookcrossingZone = (bool) ($data['isBookcrossingZone'] ?? false);
        $bookcase->position->latitude = isset($data['latitude']) ? $this->input->coordinate($data['latitude'], 90) : null;
        $bookcase->position->longitude = isset($data['longitude']) ? $this->input->coordinate($data['longitude'], 180) : null;

        return $bookcase;
    }

    /**
     * Apply a partial-update body: only the keys present are changed.
     *
     * @param array<string, mixed> $data
     */
    public function applyPatch(Bookcase $bookcase, array $data): void
    {
        if (array_key_exists('title', $data)) {
            $bookcase->title = (string) $this->input->string($data['title']);
            // The user has supplied a real title, so it's no longer the provisional
            // auto-generated one (clears the OSM "help name this bookcase" prompt) —
            // mirrors the website's full edit-form save.
            $bookcase->titleProvisional = false;
        }
        if (array_key_exists('webpage', $data)) {
            $bookcase->webpage = $this->input->string($data['webpage']);
        }
        if (array_key_exists('comment', $data)) {
            $bookcase->comment = $this->input->string($data['comment']);
        }
        if (array_key_exists('installationType', $data)) {
            $bookcase->installationType = $this->input->string($data['installationType']);
        }
        if (array_key_exists('isMobile', $data)) {
            $bookcase->isMobile = (bool) $data['isMobile'];
        }
        if (array_key_exists('isBookcrossingZone', $data)) {
            $bookcase->isBookcrossingZone = (bool) $data['isBookcrossingZone'];
        }
        if (isset($data['entryType'])) {
            $bookcase->entryType = $this->entryType($data['entryType'], 'Invalid entryType.');
        }
        if (isset($data['latitude'])) {
            $bookcase->position->latitude = $this->input->coordinate($data['latitude'], 90);
        }
        if (isset($data['longitude'])) {
            $bookcase->position->longitude = $this->input->coordinate($data['longitude'], 180);
        }
        if (array_key_exists('activeStatus', $data)) {
            $bookcase->active->status = ActiveStatus::tryFrom((string) $this->input->string($data['activeStatus']))
                ?? throw new BadRequestHttpException('Invalid activeStatus (expected "active" or "inactive").');
        }
        if (array_key_exists('statusDescription', $data)) {
            $bookcase->active->statusDescription = $this->input->string($data['statusDescription']);
        }
        if (array_key_exists('accessibilityLevel', $data)) {
            $level = $data['accessibilityLevel'];
            $bookcase->accessibility->level = $level === null ? null
                : (AccessibilityLevel::tryFrom(is_int($level) ? $level : 0)
                    ?? throw new BadRequestHttpException('Invalid accessibilityLevel (expected 1, 2 or 3).'));
        }
        if (array_key_exists('accessibilityDescription', $data)) {
            $bookcase->accessibility->description = $this->input->string($data['accessibilityDescription']);
        }
        if (isset($data['address']) && is_array($data['address'])) {
            $bookcase->address ??= new Address();
            foreach (self::ADDRESS_FIELDS as $field) {
                if (array_key_exists($field, $data['address'])) {
                    $bookcase->address->{$field} = $this->input->string($data['address'][$field]);
                }
            }
        }
    }

    private function entryType(mixed $value, string $error): EntryType
    {
        return EntryType::tryFrom((string) $this->input->string($value)) ?? throw new BadRequestHttpException($error);
    }
}

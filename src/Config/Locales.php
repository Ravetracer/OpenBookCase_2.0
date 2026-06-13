<?php declare(strict_types=1);

namespace App\Config;

/**
 * Single source of truth for the locales the UI is offered in. Keyed by locale
 * code, value is the language's own name (endonym) shown in selectors — never a
 * country flag, because a language is not a country. Add new locales here and in
 * `framework.enabled_locales` (config/packages/translation.yaml) + the catalogs.
 */
final class Locales
{
    public const DEFAULT = 'en';

    /** @var array<string, string> code => endonym */
    public const LOCALES = [
        'en' => 'English',
        'de' => 'Deutsch',
        'ru' => 'Русский',
        'nl' => 'Nederlands',
        'es' => 'Español',
        'fr' => 'Français',
    ];

    /**
     * @return string[]
     */
    public static function codes(): array
    {
        return array_keys(self::LOCALES);
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && isset(self::LOCALES[$locale]);
    }
}

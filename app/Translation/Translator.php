<?php

declare(strict_types=1);

namespace App\Translation;

/**
 * Traductions en tableaux PHP, avec locale de repli et remplacements :nom.
 */
final class Translator
{
    /** @var array<string, array<string, mixed>> */
    private array $loaded = [];

    public function __construct(
        private readonly string $langPath,
        private string $locale,
        private readonly string $fallbackLocale
    ) {
    }

    /** @param array<string, scalar> $replace */
    public function get(string $key, array $replace = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $value = $this->lookup($key, $locale);

        if (!is_string($value) && $locale !== $this->fallbackLocale) {
            $value = $this->lookup($key, $this->fallbackLocale);
        }

        $text = is_string($value) ? $value : $key;
        foreach ($replace as $name => $replacement) {
            $text = str_replace(':' . $name, (string) $replacement, $text);
        }

        return $text;
    }

    public function setLocale(string $locale): void
    {
        if (preg_match('/^[a-z]{2}$/', $locale)) {
            $this->locale = $locale;
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    private function lookup(string $key, string $locale): mixed
    {
        $this->load($locale);
        $value = $this->loaded[$locale] ?? [];

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function load(string $locale): void
    {
        if (isset($this->loaded[$locale]) || !preg_match('/^[a-z]{2}$/', $locale)) {
            return;
        }

        $file = rtrim($this->langPath, DIRECTORY_SEPARATOR) . '/' . $locale . '.php';
        $translations = is_file($file) ? require $file : [];
        $this->loaded[$locale] = is_array($translations) ? $translations : [];
    }
}

<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Env;
use App\Security\Csrf;
use App\Translation\Translator;

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('app')) {
    function app(?string $abstract = null): mixed
    {
        $app = Application::instance();
        if ($app === null) {
            throw new RuntimeException('L’application n’est pas encore initialisée.');
        }
        return $abstract === null ? $app : $app->make($abstract);
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return app()->config()->get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        return app()->basePath($path);
    }
}

if (!function_exists('e')) {
    /** Échappe systématiquement les sorties HTML dynamiques. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('__')) {
    /** @param array<string, scalar> $replace */
    function __(string $key, array $replace = []): string
    {
        /** @var Translator $translator */
        $translator = app(Translator::class);
        return $translator->get($key, $replace);
    }
}

if (!function_exists('format_money')) {
    function format_money(mixed $value): string
    {
        return number_format((float) $value, 0, ',', ' ') . ' TND';
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd/m/Y'): string
    {
        if ($date === null || $date === '') {
            return '—';
        }
        return date($format, strtotime($date));
    }
}

if (!function_exists('excerpt')) {
    function excerpt(string $text, int $length = 80): string
    {
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $length, '…');
        }
        return strlen($text) > $length ? substr($text, 0, $length - 1) . '…' : $text;
    }
}

if (!function_exists('player_age')) {
    function player_age(string $birthDate, ?string $referenceDate = null): int
    {
        return (int) ((new DateTimeImmutable($birthDate))->diff(new DateTimeImmutable($referenceDate ?? 'today'))->y);
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        /** @var Csrf $csrf */
        $csrf = app(Csrf::class);
        return $csrf->token();
    }
}

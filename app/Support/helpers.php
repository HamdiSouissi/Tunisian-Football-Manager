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

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        /** @var Csrf $csrf */
        $csrf = app(Csrf::class);
        return $csrf->token();
    }
}

<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Tunisian Football Manager'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'locale' => env('APP_LOCALE', 'fr'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'fr'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Tunis'),
    'supported_locales' => ['fr', 'ar', 'en'],
];

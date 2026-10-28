<?php

declare(strict_types=1);

return [
    'name' => env('SESSION_NAME', 'tfm_session'),
    // Vide : Application utilise storage/sessions. Un chemin absolu peut être imposé ici.
    'save_path' => env('SESSION_SAVE_PATH', ''),
    // Durée en minutes utilisée par le ramasse-miettes de PHP.
    'lifetime' => (int) env('SESSION_LIFETIME', 120),
    'secure' => env('SESSION_SECURE_COOKIE', false),
    'http_only' => true,
    'same_site' => env('SESSION_SAME_SITE', 'Lax'),
    'path' => '/',
    'domain' => '',
];

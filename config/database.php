<?php

declare(strict_types=1);

return [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => (int) env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'tunisian_football_manager'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => 'utf8mb4_unicode_ci',
    // Laisser vide pour une connexion TCP ; utile avec certains hébergements mutualisés.
    'socket' => env('DB_SOCKET', ''),
];

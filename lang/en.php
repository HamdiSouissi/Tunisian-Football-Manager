<?php

declare(strict_types=1);

return [
    'app' => ['name' => 'Tunisian Football Manager'],
    'status' => [
        'eyebrow' => 'Step 1 · Architecture and core',
        'kernel_ready' => 'Core ready',
        'title' => 'A technical foundation ready to grow with the game.',
        'description' => 'A single entry point, autonomous modules and a versioned database provide solid foundations for the Tunisian football simulation.',
        'foundation' => 'Foundation',
        'feature_mvc' => 'Native MVC, router and PSR-4 autoloading',
        'feature_pdo' => 'Secure PDO and multi-module migrations',
        'feature_security' => 'Hardened sessions and global CSRF protection',
        'feature_hooks' => 'Synchronous hooks and fr / ar / en translations',
        'environment' => 'Environment',
        'environment_name' => 'Context',
        'modules' => 'Active modules',
        'database' => 'Initialize the MySQL / MariaDB schema',
        'database_help' => 'After configuring .env and creating the database, run the versioned migrations:',
        'health_link' => 'Check the core JSON response',
        'footer' => 'Step 1 of 8 · Game data and features will be delivered incrementally.',
    ],
    'errors' => [
        'not_found' => 'The requested page could not be found.',
        'server' => 'An internal error occurred.',
        'csrf' => 'Your session expired. Reload the page and try again.',
    ],
];

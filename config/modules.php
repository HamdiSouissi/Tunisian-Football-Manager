<?php

declare(strict_types=1);

$disabled = env('MODULES_DISABLED', '');

return [
    // Tous les modules sont découverts, sauf ceux explicitement désactivés.
    'disabled' => array_values(array_filter(array_map(
        static fn (string $name): string => trim($name),
        explode(',', (string) $disabled)
    ), static fn (string $name): bool => $name !== '')),
];

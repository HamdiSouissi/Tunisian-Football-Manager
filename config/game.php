<?php

declare(strict_types=1);

/*
 * Paramètres de jeu centraux. Les formules détaillées seront branchées sur ces
 * valeurs dans les modules métier, sans constantes cachées dans les contrôleurs.
 */
return [
    'default_difficulty' => 'normal',
    'difficulties' => [
        'easy' => ['label' => 'Facile', 'budget_multiplier' => 1.25],
        'normal' => ['label' => 'Normal', 'budget_multiplier' => 1.00],
        'hard' => ['label' => 'Difficile', 'budget_multiplier' => 0.85],
        'legend' => ['label' => 'Légende', 'budget_multiplier' => 0.70],
    ],
    'season' => [
        'league_one_teams' => 16,
        'league_two_teams' => 16,
        'transfer_windows' => ['summer', 'winter'],
    ],
];

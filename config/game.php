<?php

declare(strict_types=1);

/* Paramètres centraux d'équilibrage : les services ne cachent aucune formule métier. */
return [
    'default_difficulty' => 'normal',
    'difficulties' => [
        'easy' => ['label' => 'Facile', 'budget_multiplier' => 1.30, 'ai_strength' => 0.94, 'confidence_loss' => 0.75],
        'normal' => ['label' => 'Normal', 'budget_multiplier' => 1.00, 'ai_strength' => 1.00, 'confidence_loss' => 1.00],
        'hard' => ['label' => 'Difficile', 'budget_multiplier' => 0.82, 'ai_strength' => 1.06, 'confidence_loss' => 1.20],
        'legend' => ['label' => 'Légende', 'budget_multiplier' => 0.68, 'ai_strength' => 1.12, 'confidence_loss' => 1.45],
    ],
    'season' => [
        'league_one_teams' => 16,
        'league_two_teams' => 16,
        'start_month_day' => '07-01',
        'first_match_month_day' => '08-01',
        'end_month_day' => '05-31',
        'relegated_teams' => 2,
        'promoted_teams' => 2,
        'points_win' => 3,
        'points_draw' => 1,
    ],
    'transfers' => [
        'windows' => [
            ['name' => 'summer', 'start' => '06-15', 'end' => '08-31'],
            ['name' => 'winter', 'start' => '01-01', 'end' => '01-31'],
        ],
        'minimum_offer_ratio' => 0.55,
        'counter_offer_ratio' => 0.72,
        'asking_price_ratio' => 1.18,
        'ai_daily_transfer_chance' => 0.32,
        'ai_daily_user_offer_chance' => 0.12,
    ],
    'match' => [
        'minutes' => 90,
        'halftime' => 45,
        'home_advantage' => 1.07,
        'base_chance_probability' => 0.115,
        'base_goal_probability' => 0.245,
        'max_substitutions' => 5,
        'injury_base_probability' => 0.0035,
    ],
    'training' => [
        'focus_attributes' => [
            'physical' => ['pace', 'stamina', 'strength', 'jumping'],
            'technical' => ['passing', 'dribbling', 'shooting', 'first_touch'],
            'tactical' => ['vision', 'positioning', 'work_rate', 'composure'],
            'defense' => ['tackling', 'positioning', 'strength'],
            'attack' => ['shooting', 'dribbling', 'vision'],
            'goalkeeping' => ['reflexes', 'handling', 'aerial_reach', 'command_of_area', 'kicking'],
            'recovery' => [],
        ],
        'intensity' => [
            'low' => ['development' => 0.65, 'fatigue' => 3, 'injury' => 0.002],
            'medium' => ['development' => 1.00, 'fatigue' => 7, 'injury' => 0.006],
            'high' => ['development' => 1.45, 'fatigue' => 13, 'injury' => 0.015],
        ],
    ],
    'finance' => [
        'ticket_price' => 12.0,
        'weekly_sponsor_reputation_factor' => 85.0,
        'tv_rights_league_one' => 180000.0,
        'tv_rights_league_two' => 45000.0,
        'board_confidence_win' => 2,
        'board_confidence_draw' => 0,
        'board_confidence_loss' => -3,
    ],
];

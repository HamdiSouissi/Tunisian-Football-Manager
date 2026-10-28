<?php

declare(strict_types=1);

namespace App\Engine;

/** Conditions persistantes du match et effets explicites sur le jeu. */
final class WeatherEngine
{
    public function create(array $options): array
    {
        $seed = (int)($options['fixture_id'] ?? 0);
        $types = [
            ['type' => 'clear', 'label' => 'Ciel dégagé', 'temperature' => 24, 'wind' => 8, 'pass_penalty' => 0.00, 'energy_cost' => 1.00],
            ['type' => 'wind', 'label' => 'Vent soutenu', 'temperature' => 21, 'wind' => 34, 'pass_penalty' => 0.07, 'energy_cost' => 1.03],
            ['type' => 'rain', 'label' => 'Pluie fine', 'temperature' => 18, 'wind' => 17, 'pass_penalty' => 0.09, 'energy_cost' => 1.06],
            ['type' => 'heat', 'label' => 'Forte chaleur', 'temperature' => 34, 'wind' => 5, 'pass_penalty' => 0.01, 'energy_cost' => 1.22],
        ];
        $weather = $types[$seed % count($types)];
        $weather['icon'] = ['clear' => '☀', 'wind' => '≋', 'rain' => '☂', 'heat' => '◉'][$weather['type']];
        return $weather;
    }
}

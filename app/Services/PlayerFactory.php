<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;

/** Fabrique de joueurs fictifs et calcul de l'OVR selon le poste. */
final class PlayerFactory
{
    public const ATTRIBUTES = [
        'passing','dribbling','shooting','first_touch','tackling','vision','composure','work_rate','positioning',
        'pace','stamina','strength','jumping','reflexes','handling','aerial_reach','command_of_area','kicking',
    ];

    public const SQUAD_POSITIONS = ['GK','GK','DL','DC','DC','DR','WBL','WBR','MDC','MC','MC','ML','MR','AMC','AMC','ST','ST','ST','DC','MC','MR','ST'];

    public function __construct(private readonly string $dataPath)
    {
    }

    public function make(string $position, int $age, int $overall, ?int $potential, string $nationality, DateTimeImmutable $referenceDate, ?string $firstName = null, ?string $lastName = null): array
    {
        $names = $this->names();
        $firstName ??= $names['first_names'][array_rand($names['first_names'])];
        $lastName ??= $names['last_names'][array_rand($names['last_names'])];
        $overall = max(35, min(92, $overall));
        $potential = max($overall, min(99, $potential ?? ($overall + ($age < 23 ? random_int(5, 18) : 0))));
        $attributes = $this->attributesFor($position, $overall);
        $birthDate = $referenceDate->sub(new \DateInterval('P' . $age . 'Y'))->sub(new \DateInterval('P' . random_int(0, 360) . 'D'));
        $value = $this->marketValue($overall, $potential, $age);

        return [
            'player' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate->format('Y-m-d'),
                'nationality' => $nationality,
                'primary_position' => $position,
                'secondary_positions' => json_encode($this->secondaryPositions($position), JSON_THROW_ON_ERROR),
                'preferred_foot' => random_int(1, 100) <= 18 ? 'left' : (random_int(1, 100) <= 5 ? 'both' : 'right'),
                'height_cm' => $position === 'GK' ? random_int(184, 199) : random_int(168, 193),
                'weight_kg' => random_int(65, 88),
                'market_value' => $value,
                'overall_rating' => self::overall($position, $attributes),
                'potential' => $potential,
                'form' => random_int(45, 75),
                'morale' => random_int(55, 82),
                'fatigue' => 0,
            ],
            'attributes' => $attributes,
            'salary' => round(max(250, $overall * $overall * 0.48), 2),
        ];
    }

    public static function overall(string $position, array $a): int
    {
        $weights = match (self::group($position)) {
            'GK' => ['reflexes' => .25, 'handling' => .20, 'aerial_reach' => .15, 'command_of_area' => .15, 'kicking' => .10, 'composure' => .10, 'positioning' => .05],
            'DEF' => ['tackling' => .22, 'positioning' => .18, 'strength' => .14, 'pace' => .10, 'jumping' => .10, 'passing' => .08, 'composure' => .08, 'work_rate' => .10],
            'MID' => ['passing' => .20, 'vision' => .15, 'first_touch' => .12, 'positioning' => .12, 'work_rate' => .10, 'stamina' => .10, 'dribbling' => .08, 'composure' => .08, 'tackling' => .05],
            'WING' => ['pace' => .18, 'dribbling' => .17, 'passing' => .12, 'first_touch' => .10, 'shooting' => .12, 'vision' => .10, 'stamina' => .08, 'work_rate' => .07, 'composure' => .06],
            default => ['shooting' => .24, 'composure' => .13, 'first_touch' => .12, 'dribbling' => .10, 'pace' => .12, 'positioning' => .10, 'strength' => .08, 'vision' => .06, 'jumping' => .05],
        };
        $score = 0.0;
        foreach ($weights as $attribute => $weight) {
            $score += ((float) ($a[$attribute] ?? 40)) * $weight;
        }
        return max(1, min(100, (int) round($score)));
    }

    public static function group(string $position): string
    {
        return match ($position) {
            'GK' => 'GK',
            'DL','DC','DR','WBL','WBR' => 'DEF',
            'MDC','MC','AMC' => 'MID',
            'ML','MR' => 'WING',
            default => 'ATT',
        };
    }

    public static function secondaryPositions(string $position): array
    {
        return match ($position) {
            'DL' => ['WBL'], 'DR' => ['WBR'], 'WBL' => ['DL','ML'], 'WBR' => ['DR','MR'],
            'DC' => ['MDC'], 'MDC' => ['MC','DC'], 'MC' => ['MDC','AMC'], 'AMC' => ['MC','ST'],
            'ML' => ['MR','AMC'], 'MR' => ['ML','AMC'], 'ST' => ['AMC'], default => [],
        };
    }

    private function attributesFor(string $position, int $overall): array
    {
        $attributes = [];
        foreach (self::ATTRIBUTES as $attribute) {
            $attributes[$attribute] = max(15, min(95, $overall + random_int(-12, 7)));
        }
        if ($position === 'GK') {
            foreach (['passing','dribbling','shooting','first_touch','tackling','pace'] as $attribute) {
                $attributes[$attribute] = random_int(20, 48);
            }
            foreach (['reflexes','handling','aerial_reach','command_of_area','kicking'] as $attribute) {
                $attributes[$attribute] = max(35, min(96, $overall + random_int(-5, 9)));
            }
        } else {
            foreach (['reflexes','handling','aerial_reach','command_of_area','kicking'] as $attribute) {
                $attributes[$attribute] = random_int(10, 35);
            }
        }
        $boosts = match (self::group($position)) {
            'DEF' => ['tackling','positioning','strength','jumping'],
            'MID' => ['passing','vision','first_touch','stamina'],
            'WING' => ['pace','dribbling','passing'],
            'ATT' => ['shooting','composure','positioning'],
            default => [],
        };
        foreach ($boosts as $attribute) {
            $attributes[$attribute] = min(96, $attributes[$attribute] + random_int(4, 10));
        }
        return $attributes;
    }

    private function marketValue(int $overall, int $potential, int $age): float
    {
        $ageFactor = $age <= 21 ? 1.25 : ($age <= 27 ? 1.10 : ($age <= 30 ? .85 : .55));
        $potentialFactor = 1 + max(0, $potential - $overall) / 60;
        return round(max(10000, (($overall - 34) ** 3) * 95 * $ageFactor * $potentialFactor), 2);
    }

    private function names(): array
    {
        static $names = null;
        if ($names === null) {
            $names = json_decode((string) file_get_contents($this->dataPath . '/names.json'), true, 512, JSON_THROW_ON_ERROR);
        }
        return $names;
    }
}

<?php

declare(strict_types=1);

namespace App\Engine;

use App\Services\PlayerFactory;
use App\Services\TacticService;

/** Calcule les cibles collectives et individuelles sur un terrain de 105 × 68 mètres. */
final class TacticalEngine
{
    public const WIDTH = 105.0;
    public const HEIGHT = 68.0;

    /** @return array<int,array{x:float,y:float}> */
    public function initialPositions(array $state, string $side): array
    {
        $positions = [];
        foreach ($state['sides'][$side]['tactic']['lineup'] as $entry) {
            $positions[(int)$entry['player_id']] = $this->basePosition($entry, $side, (string)$state['sides'][$side]['tactic']['formation']);
        }
        return $positions;
    }

    /** @return array{x:float,y:float} */
    public function basePosition(array $entry, string $side, ?string $formation = null): array
    {
        $position = (string)$entry['position'];
        if ($position === 'GK') {
            return ['x' => $side === 'home' ? 5.5 : 99.5, 'y' => 34.0];
        }

        $slot = (int)($entry['slot'] ?? 0);
        $coordinates = $formation !== null && isset(TacticService::FORMATION_COORDINATES[$formation][$slot])
            ? TacticService::FORMATION_COORDINATES[$formation][$slot]
            : [(float)($entry['x'] ?? 50), (float)($entry['y'] ?? 50)];
        // L'éditeur tactique utilise Y=88 pour le gardien et Y=14 pour la pointe.
        $homeX = 105 - ((float)$coordinates[1] * 1.05);
        $x = $side === 'home' ? $homeX : 105 - $homeX;
        $y = ((float)$coordinates[0] / 100) * self::HEIGHT;
        return ['x' => $this->clamp($x, 2.0, 103.0), 'y' => $this->clamp($y, 2.0, 66.0)];
    }

    /**
     * Les déplacements de bloc sont communs à la ligne ; les courses individuelles restent bornées.
     *
     * @return array<int,array{x:float,y:float}>
     */
    public function targets(array $state, string $side): array
    {
        $targets = [];
        $ballSide = (string)($state['spatial']['ball']['side'] ?? 'home');
        $ball = $state['spatial']['ball'];
        $attacking = $ballSide === $side;
        $direction = $side === 'home' ? 1 : -1;
        $tactic = $state['sides'][$side]['tactic'];
        $mentality = (string)$tactic['mentality'];
        $pressing = (string)$tactic['pressing'];
        $line = (string)$tactic['defensive_line'];
        $width = (string)$tactic['width'];
        $minute = (int)$state['minute'];
        $ownScore = (int)$state[$side . '_score'];
        $otherSide = $side === 'home' ? 'away' : 'home';
        $otherScore = (int)$state[$otherSide . '_score'];
        $finalPush = $minute >= 80 && $ownScore < $otherScore;

        $attackShift = ['very_defensive' => 1.5, 'defensive' => 3.0, 'balanced' => 5.0, 'attacking' => 7.5, 'very_attacking' => 10.0][$mentality] ?? 5.0;
        $defendShift = $pressing === 'low' ? -8.0 : ($pressing === 'high' ? -2.5 : -5.0);
        $defendShift += $line === 'deep' ? -3.5 : ($line === 'high' ? 4.0 : 0.0);
        $blockShift = $attacking ? $attackShift : $defendShift;
        $widthFactor = $width === 'narrow' ? 0.80 : ($width === 'wide' ? 1.12 : 1.0);

        foreach ($state['sides'][$side]['tactic']['lineup'] as $entry) {
            $playerId = (int)$entry['player_id'];
            $base = $this->basePosition($entry, $side, (string)$tactic['formation']);
            $position = (string)$entry['position'];
            $group = PlayerFactory::group($position);
            $player = $state['sides'][$side]['pool'][$playerId] ?? [];
            $instruction = (string)($entry['instruction'] ?? 'normal');
            $role = (string)($entry['role'] ?? 'support');

            $x = $base['x'] + $direction * $blockShift;
            $y = 34 + ($base['y'] - 34) * $widthFactor;

            // Le bloc suit le ballon sans désagréger les lignes.
            $ballInfluence = $group === 'DEF' ? 0.15 : ($group === 'MID' ? 0.11 : 0.07);
            $x += ((float)$ball['x'] - $base['x']) * $ballInfluence;
            $y += ((float)$ball['y'] - $base['y']) * ($attacking ? 0.07 : 0.13);

            if ($attacking) {
                if (in_array($position, ['DL', 'DR', 'WBL', 'WBR', 'ML', 'MR'], true)) {
                    $run = $instruction === 'attack' || $role === 'attack' ? 8.0 : 3.0;
                    if ($instruction === 'stay_back' || $role === 'defend') {
                        $run = -2.0;
                    }
                    $x += $direction * $run;
                }
                if ($group === 'ATT') {
                    $depth = 3.0 + (float)($player['positioning'] ?? 50) / 25;
                    if ($line === 'high' || $finalPush) {
                        $depth += 2.0;
                    }
                    $x += $direction * $depth;
                }
                if ($position === 'MDC') {
                    // Le récupérateur reste l'écran devant la défense, même en phase offensive.
                    $x = $side === 'home' ? min($x, 48.0) : max($x, 57.0);
                }
                if ($group === 'DEF' && $finalPush && $position === 'DC') {
                    $x += $direction * 8.0;
                }
            } elseif ($group === 'ATT') {
                // Les attaquants restent une première solution de contre sans courir au hasard.
                $x -= $direction * 1.5;
            }

            if ($group === 'GK') {
                $x = $base['x'];
                $y = 34 + ((float)$ball['y'] - 34) * 0.10;
            }

            $targets[$playerId] = [
                'x' => $this->clamp($x, 1.5, 103.5),
                'y' => $this->clamp($y, 1.5, 66.5),
            ];
        }
        return $targets;
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}

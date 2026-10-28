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
            $slot = (int)($entry['slot'] ?? 0);
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

            $target = $this->individualTarget($state, $side, $entry, $base, ['x'=>$x,'y'=>$y], $attacking);
            $target = $this->separateFromTeammates($state, $side, $playerId, $slot, $target);
            $targets[$playerId] = [
                'x' => $this->clamp($target['x'], 1.5, 103.5),
                'y' => $this->clamp($target['y'], 1.5, 66.5),
            ];
        }
        return $targets;
    }

    /** Courses individuelles liées au poste, au rôle et à la situation réelle du ballon. */
    private function individualTarget(array $state, string $side, array $entry, array $base, array $target, bool $attacking): array
    {
        $playerId = (int)$entry['player_id'];
        $position = (string)$entry['position'];
        $group = PlayerFactory::group($position);
        if ($group === 'GK' || (int)$state['spatial']['ball']['owner_id'] === $playerId) {
            return $target;
        }
        $player = $state['sides'][$side]['pool'][$playerId] ?? [];
        $role = (string)($entry['role'] ?? 'support');
        $instruction = (string)($entry['instruction'] ?? 'normal');
        $slot = (int)($entry['slot'] ?? 0);
        $direction = $side === 'home' ? 1 : -1;
        $ball = $state['spatial']['ball'];
        $current = $state['spatial']['players'][$side][$playerId] ?? $base;
        $energy = (float)($state['sides'][$side]['energy'] ?? 100) / 100;
        $run = (1.8 + (float)($player['pace'] ?? 50) / 38 + (float)($player['work_rate'] ?? 50) / 45)
            * (0.75 + $energy * 0.25) * (1 - (float)($player['fatigue'] ?? 0) / 260);
        $sameFlank = ((float)$base['y'] < 34) === ((float)$ball['y'] < 34);
        $supportDistance = 6 + (float)($player['positioning'] ?? 50) / 14;

        if ($attacking) {
            if (in_array($position, ['DL','DR','WBL','WBR'], true)) {
                if ($sameFlank && ($role === 'attack' || $instruction === 'attack' || (float)($player['work_rate'] ?? 0) >= 68)) {
                    $target['x'] += $direction * $run;
                    $target['y'] += (float)$base['y'] < 34 ? -1.8 : 1.8;
                } else {
                    $target['x'] += $direction * $run * 0.25;
                    $target['y'] += (34 - (float)$target['y']) * 0.08;
                }
            } elseif (in_array($position, ['ML','MR'], true) || $group === 'WING') {
                if ($sameFlank) {
                    $target['x'] += $direction * $run * 0.55;
                    $target['y'] += (float)$base['y'] < 34 ? -2.5 : 2.5;
                } else {
                    // L'ailier opposé rentre dans la surface au lieu de suivre le même déplacement que son couloir.
                    $target['x'] += $direction * $run * 0.85;
                    $target['y'] += (34 - (float)$target['y']) * 0.24;
                }
            } elseif ($position === 'MDC') {
                $target['x'] = $side === 'home' ? min($target['x'], (float)$ball['x'] - 5) : max($target['x'], (float)$ball['x'] + 5);
                $target['y'] += ((float)$ball['y'] - (float)$target['y']) * 0.22;
            } elseif ($group === 'MID') {
                $forwardSupport = $role === 'attack' || $instruction === 'attack' ? 0.45 : -0.35;
                $supportX = (float)$ball['x'] + $direction * $supportDistance * $forwardSupport;
                $lane = $slot % 2 === 0 ? 1 : -1;
                $laneY = (float)$ball['y'] + $lane * $supportDistance;
                if ($laneY < 5 || $laneY > 63) $laneY = (float)$ball['y'] - $lane * $supportDistance;
                $target['x'] = $target['x'] * 0.62 + $supportX * 0.38;
                $target['y'] = $target['y'] * 0.68 + $laneY * 0.32;
            } elseif ($group === 'ATT') {
                if ($role === 'playmaker') {
                    $target['x'] -= $direction * 3.5;
                    $target['y'] += ((float)$ball['y'] - (float)$target['y']) * 0.20;
                } else {
                    $ballInFinalThird = $side === 'home' ? (float)$ball['x'] > 70 : (float)$ball['x'] < 35;
                    if ($ballInFinalThird) {
                        $nearPost = (float)$ball['y'] < 34 ? 27.0 : 41.0;
                        $farPost = (float)$ball['y'] < 34 ? 43.0 : 25.0;
                        $targetY = abs((float)$base['y'] - 34) < 4 || $sameFlank ? $nearPost : $farPost;
                        $target['x'] += $direction * $run * 0.65;
                        $target['y'] = $target['y'] * 0.48 + $targetY * 0.52;
                    } elseif ($role === 'target') {
                        $target['y'] += (34 - (float)$target['y']) * 0.16;
                    }
                }
            }
            return $target;
        }

        $pressing = (string)$state['sides'][$side]['tactic']['pressing'];
        $distanceToBall = hypot((float)$current['x'] - (float)$ball['x'], (float)$current['y'] - (float)$ball['y']);
        $rank = 0;
        foreach ($state['spatial']['players'][$side] as $teammate) {
            if (hypot((float)$teammate['x'] - (float)$ball['x'], (float)$teammate['y'] - (float)$ball['y']) < $distanceToBall) $rank++;
        }
        if ($pressing !== 'low' && $rank === 0 && ($pressing === 'high' || $distanceToBall < 24)) {
            // Un seul joueur attaque le porteur ; les autres conservent marquage et couverture.
            return ['x'=>$target['x'] * 0.25 + (float)$ball['x'] * 0.75, 'y'=>$target['y'] * 0.25 + (float)$ball['y'] * 0.75];
        }
        if (in_array($group, ['DEF','MID'], true)) {
            $opponent = $this->nearestOpponent($state, $side, $current);
            if ($opponent !== null && $opponent['distance'] < ($group === 'DEF' ? 17 : 11)) {
                $markX = $opponent['x'] - $direction * 1.2;
                $weight = $group === 'DEF' ? 0.65 : 0.35;
                $target['x'] = $target['x'] * (1 - $weight) + $markX * $weight;
                $target['y'] = $target['y'] * (1 - $weight) + $opponent['y'] * $weight;
            }
        }
        return $target;
    }

    /** Évite que deux cibles individuelles se retrouvent sur le même point. */
    private function separateFromTeammates(array $state, string $side, int $playerId, int $slot, array $target): array
    {
        if ((int)$state['spatial']['ball']['owner_id'] === $playerId) return $target;
        foreach ($state['spatial']['players'][$side] as $teammateId => $teammate) {
            if ((int)$teammateId === $playerId) continue;
            $distance = hypot((float)$target['x'] - (float)$teammate['x'], (float)$target['y'] - (float)$teammate['y']);
            if ($distance < 1.8) {
                $away = (float)$target['y'] <= (float)$teammate['y'] ? -1 : 1;
                $target['y'] += $away * 2.2;
                $target['x'] += $slot % 2 === 0 ? 0.6 : -0.6;
                break;
            }
        }
        return $target;
    }

    private function nearestOpponent(array $state, string $side, array $position): ?array
    {
        $opponentSide = $side === 'home' ? 'away' : 'home';
        $nearest = null;
        foreach ($state['spatial']['players'][$opponentSide] as $opponent) {
            $distance = hypot((float)$opponent['x'] - (float)$position['x'], (float)$opponent['y'] - (float)$position['y']);
            if ($nearest === null || $distance < $nearest['distance']) {
                $nearest = ['x'=>(float)$opponent['x'], 'y'=>(float)$opponent['y'], 'distance'=>$distance];
            }
        }
        return $nearest;
    }

    private function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
}

<?php

declare(strict_types=1);

namespace App\Engine;

use App\Services\PlayerFactory;

/** Décisions du porteur et évaluation des solutions, sans déplacement aléatoire. */
final class AIEngine
{
    public function decide(array $state, string $side, int $ownerId, float $pressure): array
    {
        $player = $state['sides'][$side]['pool'][$ownerId] ?? [];
        $position = $state['spatial']['players'][$side][$ownerId];
        $tactic = $state['sides'][$side]['tactic'];
        $direction = $side === 'home' ? 1 : -1;
        $goalX = $side === 'home' ? TacticalEngine::WIDTH : 0.0;
        $goalDistance = hypot($goalX - (float)$position['x'], 34 - (float)$position['y']);
        $composure = $this->attribute($player, 'composure') * ($side === 'home' ? 1 + ((float)($state['home_advantage'] ?? 1.0) - 1) * 0.35 : 1.0);
        $dribbling = $this->attribute($player, 'dribbling');
        $vision = $this->attribute($player, 'vision');
        $shooting = $this->attribute($player, 'shooting');
        $tempo = (string)$tactic['tempo'];

        // Une frappe n'est envisagée que dans une zone et avec une qualité de tir cohérentes.
        $shootScore = $shooting * 0.58 + $composure * 0.27 + $this->attribute($player, 'positioning') * 0.15;
        $shotFrequency = $tempo === 'fast' ? 0.24 : ($tempo === 'slow' ? 0.14 : 0.18);
        if ($goalDistance < 26 && $pressure > 1.1 && $shootScore + $this->random(-8, 8) > 62 - (26 - $goalDistance) * 0.42 && $this->random() < $shotFrequency) {
            return ['action' => 'shoot', 'goal_distance' => $goalDistance];
        }

        $dribbleScore = $dribbling * 0.62 + $this->attribute($player, 'pace') * 0.23 + $composure * 0.15;
        if ($pressure > 0.6 && $pressure < 9.0 && $dribbleScore + $this->random(-8, 8) > 60 && $this->random() < 0.22) {
            return [
                'action' => 'dribble',
                'target' => [
                    'x' => max(1.5, min(103.5, (float)$position['x'] + $direction * 5.5)),
                    'y' => max(1.5, min(66.5, (float)$position['y'] + ((float)$position['y'] < 34 ? 1.2 : -1.2))),
                ],
            ];
        }

        $pass = $this->bestPass($state, $side, $ownerId);
        $passUrgency = $pressure < 3.2 ? 22 : 7;
        $passUrgency += $tempo === 'fast' ? 8 : ($tempo === 'slow' ? -5 : 0);
        $passUrgency += (100 - $composure) * 0.04;
        $passLikelihood = $pressure < 1.6 ? 1.0 : ($pressure < 4.5 ? 0.45 + $vision / 250 : ($passUrgency + $vision * 0.36) / 100);
        if ($pass !== null && $this->random() < min(1.0, $passLikelihood)) {
            return $pass;
        }

        $dribbleScore = $dribbling * 0.62 + $this->attribute($player, 'pace') * 0.23 + $composure * 0.15;
        if ($pressure > 1.6 && $pressure < 3.4 && $dribbleScore + $this->random(-10, 10) > 67 && $this->random() < 0.20) {
            return [
                'action' => 'dribble',
                'target' => [
                    'x' => max(1.5, min(103.5, (float)$position['x'] + $direction * 5.5)),
                    'y' => max(1.5, min(66.5, (float)$position['y'] + ((float)$position['y'] < 34 ? 1.2 : -1.2))),
                ],
            ];
        }

        return $pass ?? ['action' => 'hold'];
    }

    public function bestPass(array $state, string $side, int $ownerId): ?array
    {
        $owner = $state['sides'][$side]['pool'][$ownerId] ?? [];
        $origin = $state['spatial']['players'][$side][$ownerId];
        $direction = $side === 'home' ? 1 : -1;
        $tactic = $state['sides'][$side]['tactic'];
        $opponentSide = $side === 'home' ? 'away' : 'home';
        $best = null;
        $bestScore = -INF;

        foreach ($state['spatial']['players'][$side] as $candidateId => $candidate) {
            $candidateId = (int)$candidateId;
            if ($candidateId === $ownerId) {
                continue;
            }
            $entry = $this->tacticEntry($state, $side, $candidateId);
            $candidatePlayer = $state['sides'][$side]['pool'][$candidateId] ?? [];
            $distance = hypot((float)$candidate['x'] - (float)$origin['x'], (float)$candidate['y'] - (float)$origin['y']);
            if ($distance < 3 || $distance > 48) {
                continue;
            }
            $forward = ((float)$candidate['x'] - (float)$origin['x']) * $direction;
            $openness = $this->nearestOpponentDistance($state, $opponentSide, $candidate);
            $group = PlayerFactory::group((string)($entry['position'] ?? 'MC'));
            $score = $forward * 0.85 - $distance * 0.12 + $openness * 2.4;
            $score += $this->attribute($candidatePlayer, 'positioning') * 0.055;
            $score += in_array($group, ['ATT', 'WING'], true) ? 4.0 : 0.0;
            $score += (float)$state['sides'][$side]['cohesion'] * 4.0;
            if (($tactic['playing_style'] ?? '') === 'possession' && $distance < 24) {
                $score += 6.0;
            }
            if (in_array($tactic['playing_style'] ?? '', ['direct', 'long_ball', 'counter'], true) && $forward > 12) {
                $score += 7.0;
            }
            if (($tactic['mentality'] ?? '') === 'very_defensive' && $forward < 0) {
                $score += 4.0;
            }
            if ($this->isCross($side, $origin, $candidate, $entry)) {
                $score += 8.0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $kind = $distance > 27 ? 'long_pass' : 'pass';
                if ($this->isCross($side, $origin, $candidate, $entry)) {
                    $kind = 'cross';
                }
                $best = [
                    'action' => $kind,
                    'target_id' => $candidateId,
                    'target_side' => $side,
                    'target' => ['x' => (float)$candidate['x'], 'y' => (float)$candidate['y']],
                    'distance' => $distance,
                    'openness' => $openness,
                ];
            }
        }

        if ($best === null) {
            return null;
        }

        $passing = $this->attribute($owner, 'passing');
        $vision = $this->attribute($owner, 'vision');
        $weatherPenalty = (float)($state['weather']['pass_penalty'] ?? 0.0);
        $success = 0.62 + $passing / 300 + $vision / 500 + (float)$state['sides'][$side]['cohesion'] * 0.05;
        $success -= $best['distance'] / 140 + $weatherPenalty;
        $success += min(8.0, $best['openness']) / 120;
        $success *= $side === 'home' ? 1 + ((float)($state['home_advantage'] ?? 1.0) - 1) * 0.5 : 1.0;
        if ($this->random() > max(0.30, min(0.97, $success))) {
            if (abs((float)$origin['y'] - 34) > 18 && $this->random() < 0.20) {
                $best['target']['x'] = max(0.5, min(104.5, (float)$best['target']['x']));
                $best['target']['y'] = (float)$origin['y'] < 34 ? -1.0 : 69.0;
                $best['out_of_play'] = true;
                return $best;
            }
            $interceptor = $this->interceptor($state, $opponentSide, $origin, $best['target']);
            if ($interceptor === null) {
                $interceptor = $this->nearestOpponentToPoint($state, $opponentSide, $best['target']);
            }
            if ($interceptor !== null) {
                $best['intercepted_by'] = $interceptor['id'];
                $best['target_id'] = $interceptor['id'];
                $best['target_side'] = $opponentSide;
                $best['target'] = $interceptor['position'];
            }
        }
        return $best;
    }

    public function isOffsidePosition(array $state, string $side, array $origin, array $target): bool
    {
        $home = $side === 'home';
        if (($home && (float)$target['x'] <= 52.5) || (!$home && (float)$target['x'] >= 52.5)) return false;
        if (($home && (float)$target['x'] <= (float)$origin['x']) || (!$home && (float)$target['x'] >= (float)$origin['x'])) return false;
        $defenders = array_map(static fn (array $position): float => (float)$position['x'], array_values($state['spatial']['players'][$home ? 'away' : 'home']));
        $home ? rsort($defenders) : sort($defenders);
        if (count($defenders) < 2) return false;
        $secondLast = $defenders[1];
        // À cette échelle temporelle, seuls les hors-jeu francs sont sanctionnés.
        return $home ? (float)$target['x'] > $secondLast + 3.0 : (float)$target['x'] < $secondLast - 3.0;
    }

    public function nearestOpponentDistance(array $state, string $opponentSide, array $position): float
    {
        $nearest = 999.0;
        foreach ($state['spatial']['players'][$opponentSide] as $opponent) {
            $nearest = min($nearest, hypot((float)$opponent['x'] - (float)$position['x'], (float)$opponent['y'] - (float)$position['y']));
        }
        return $nearest;
    }

    public function interceptor(array $state, string $side, array $origin, array $target): ?array
    {
        $best = null;
        $bestDistance = 999.0;
        $length = max(0.001, hypot((float)$target['x'] - (float)$origin['x'], (float)$target['y'] - (float)$origin['y']));
        foreach ($state['spatial']['players'][$side] as $id => $position) {
            // Projection du défenseur sur la ligne de passe pour déterminer une interception crédible.
            $t = max(0.0, min(1.0, (((float)$position['x'] - (float)$origin['x']) * ((float)$target['x'] - (float)$origin['x']) + ((float)$position['y'] - (float)$origin['y']) * ((float)$target['y'] - (float)$origin['y'])) / ($length * $length)));
            $projection = ['x' => (float)$origin['x'] + ((float)$target['x'] - (float)$origin['x']) * $t, 'y' => (float)$origin['y'] + ((float)$target['y'] - (float)$origin['y']) * $t];
            $distance = hypot((float)$position['x'] - $projection['x'], (float)$position['y'] - $projection['y']);
            $player = $state['sides'][$side]['pool'][$id] ?? [];
            $reach = 1.4 + $this->attribute($player, 'positioning') / 55 + $this->attribute($player, 'tackling') / 90;
            if ($distance < $reach && $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = ['id' => (int)$id, 'position' => ['x' => (float)$position['x'], 'y' => (float)$position['y']]];
            }
        }
        return $best;
    }

    private function nearestOpponentToPoint(array $state, string $side, array $point): ?array
    {
        $best = null;
        $bestDistance = INF;
        foreach ($state['spatial']['players'][$side] as $id => $position) {
            $distance = hypot((float)$position['x'] - (float)$point['x'], (float)$position['y'] - (float)$point['y']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = ['id' => (int)$id, 'position' => ['x' => (float)$position['x'], 'y' => (float)$position['y']]];
            }
        }
        return $best;
    }

    public function attribute(array $player, string $name): float
    {
        $value = (float)($player[$name] ?? 50);
        $form = 0.90 + (float)($player['form'] ?? 50) / 500;
        $morale = 0.95 + (float)($player['morale'] ?? 50) / 1000;
        $fatigue = 1 - (float)($player['fatigue'] ?? 0) / 300;
        $experience = 0.97 + min(100, (float)($player['experience'] ?? 0)) / 1000;
        return $value * $form * $morale * $fatigue * $experience;
    }

    private function isCross(string $side, array $origin, array $target, array $entry): bool
    {
        $attackingX = $side === 'home' ? (float)$origin['x'] > 72 : (float)$origin['x'] < 33;
        $wide = abs((float)$origin['y'] - 34) > 19;
        $centralTarget = abs((float)$target['y'] - 34) < 15;
        return $attackingX && $wide && $centralTarget && in_array(PlayerFactory::group((string)($entry['position'] ?? '')), ['ATT', 'MID'], true);
    }

    private function tacticEntry(array $state, string $side, int $playerId): array
    {
        foreach ($state['sides'][$side]['tactic']['lineup'] as $entry) {
            if ((int)$entry['player_id'] === $playerId) {
                return $entry;
            }
        }
        return [];
    }

    private function random(int $minimum = 0, int $maximum = 1000000): float
    {
        if ($maximum === 1000000 && $minimum === 0) {
            return random_int(0, 1000000) / 1000000;
        }
        return random_int($minimum, $maximum);
    }
}

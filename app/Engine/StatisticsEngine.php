<?php

declare(strict_types=1);

namespace App\Engine;

/** Notes de match et distinction du joueur le plus influent. */
final class StatisticsEngine
{
    public function initialize(array $state): array
    {
        $state['player_ratings'] = ['home' => [], 'away' => []];
        $state['player_minutes'] = ['home' => [], 'away' => []];
        foreach (['home', 'away'] as $side) {
            foreach ($state['sides'][$side]['lineup'] as $playerId => $player) {
                $state['player_ratings'][$side][(int)$playerId] = 6.0;
                $state['player_minutes'][$side][(int)$playerId] = 0;
            }
        }
        $state['man_of_the_match'] = null;
        $state['experience_gained'] = ['home' => [], 'away' => []];
        return $state;
    }

    public function record(array $state, array $events): array
    {
        foreach (['home', 'away'] as $side) {
            foreach ($state['spatial']['players'][$side] as $playerId => $position) {
                $state['player_minutes'][$side][(int)$playerId] = (int)($state['player_minutes'][$side][(int)$playerId] ?? 0) + 1;
            }
        }
        foreach ($events as $event) {
            $side = $event['side'] ?? null;
            if (!in_array($side, ['home', 'away'], true)) {
                continue;
            }
            $playerId = (int)($event['player_id'] ?? 0);
            $relatedId = (int)($event['related_player_id'] ?? 0);
            $type = (string)$event['type'];
            $delta = match ($type) {
                'goal', 'penalty_goal' => 1.15,
                'penalty_miss' => -0.75,
                'shot_on_target' => 0.18,
                'chance' => -0.08,
                'yellow_card' => -0.30,
                'red_card' => -1.10,
                'foul' => -0.04,
                'injury' => -0.10,
                'interception', 'tackle' => 0.12,
                'offside' => -0.12,
                'cross' => 0.06,
                'dribble' => 0.05,
                'pass', 'long_pass' => 0.02,
                default => 0.0,
            };
            $this->adjust($state, $side, $playerId, $delta);
            if (in_array($type, ['goal', 'penalty_goal'], true) && $relatedId > 0) {
                $this->adjust($state, $side, $relatedId, 0.65);
            }
            if (in_array($type, ['shot_on_target', 'penalty_miss'], true) && $relatedId > 0) {
                $goalkeeperSide = $side === 'home' ? 'away' : 'home';
                $this->adjust($state, $goalkeeperSide, $relatedId, $type === 'penalty_miss' ? 0.45 : 0.18);
            }
        }
        return $state;
    }

    public function synchronize(array $state): array
    {
        foreach (['home', 'away'] as $side) {
            foreach ($state['sides'][$side]['lineup'] as $playerId => $player) {
                $state['player_ratings'][$side][(int)$playerId] ??= 6.0;
                $state['player_minutes'][$side][(int)$playerId] ??= 0;
            }
        }
        return $state;
    }

    public function finalize(array $state): array
    {
        $best = null;
        foreach (['home', 'away'] as $side) {
            $state['experience_gained'][$side] = [];
            foreach ($state['player_ratings'][$side] as $playerId => $rating) {
                $minutes = (int)($state['player_minutes'][$side][$playerId] ?? 0);
                $state['experience_gained'][$side][(int)$playerId] = $minutes > 0 ? ($minutes >= 45 ? 2 : 1) + ((float)$rating >= 8.0 ? 1 : 0) : 0;
                if ($best === null || $rating > $best['rating']) {
                    $player = $state['sides'][$side]['pool'][$playerId] ?? [];
                    $best = [
                        'id' => (int)$playerId,
                        'side' => $side,
                        'name' => trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? '')),
                        'rating' => round((float)$rating, 1),
                    ];
                }
            }
        }
        $state['man_of_the_match'] = $best;
        return $state;
    }

    private function adjust(array &$state, string $side, int $playerId, float $delta): void
    {
        if ($playerId <= 0 || !isset($state['player_ratings'][$side][$playerId])) {
            return;
        }
        $state['player_ratings'][$side][$playerId] = round(max(1.0, min(10.0, (float)$state['player_ratings'][$side][$playerId] + $delta)), 2);
    }
}

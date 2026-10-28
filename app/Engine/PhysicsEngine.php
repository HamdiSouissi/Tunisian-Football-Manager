<?php

declare(strict_types=1);

namespace App\Engine;

/** Déplacements continus des joueurs et trajectoires du ballon, sans téléportation. */
final class PhysicsEngine
{
    public function movePlayers(array $state, array $targets): array
    {
        foreach (['home', 'away'] as $side) {
            $energy = (float)($state['sides'][$side]['energy'] ?? 100) / 100;
            foreach ($state['spatial']['players'][$side] as $playerId => $position) {
                if (!isset($targets[$side][$playerId])) {
                    continue;
                }
                $player = $state['sides'][$side]['pool'][$playerId] ?? [];
                $pace = (float)($player['pace'] ?? 50);
                $stamina = (float)($player['stamina'] ?? 50);
                $fatigue = (float)($player['fatigue'] ?? 0) / 100;
                $workRate = (float)($player['work_rate'] ?? 50) / 100;
                $speed = (0.48 + $pace / 115 + $workRate * 0.18) * (0.72 + $energy * 0.28) * (0.94 + $stamina / 800) * (1 - $fatigue * 0.22);
                $state['spatial']['players'][$side][$playerId] = $this->moveTowards($position, $targets[$side][$playerId], $speed);
            }
        }
        return $state;
    }

    /** @return array{x:float,y:float} */
    public function moveTowards(array $current, array $target, float $maximumDistance): array
    {
        $dx = (float)$target['x'] - (float)$current['x'];
        $dy = (float)$target['y'] - (float)$current['y'];
        $distance = hypot($dx, $dy);
        if ($distance <= $maximumDistance || $distance < 0.001) {
            return ['x' => (float)$target['x'], 'y' => (float)$target['y']];
        }
        $ratio = $maximumDistance / $distance;
        return ['x' => (float)$current['x'] + $dx * $ratio, 'y' => (float)$current['y'] + $dy * $ratio];
    }

    public function kick(array $spatial, int $targetOwnerId, string $targetSide, array $target, string $kind, int $steps, float $height = 0.0): array
    {
        $ball = $spatial['ball'];
        $ball['start_x'] = (float)$ball['x'];
        $ball['start_y'] = (float)$ball['y'];
        $ball['target_x'] = (float)$target['x'];
        $ball['target_y'] = (float)$target['y'];
        $distance = hypot($ball['target_x'] - $ball['start_x'], $ball['target_y'] - $ball['start_y']);
        // Vitesse maximale en mètres par tick de 100 ms : la balle ne traverse plus le terrain instantanément.
        $maximumSpeed = match ($kind) {
            'shot' => 5.5,
            'penalty' => 4.5,
            'long_pass' => 3.0,
            'cross' => 2.2,
            'restart' => 3.5,
            'tackle' => 2.5,
            default => 2.4,
        };
        $steps = max(1, $steps, (int)ceil($distance / $maximumSpeed));
        $ball['target_id'] = $targetOwnerId;
        $ball['target_side'] = $targetSide;
        $ball['in_transit'] = true;
        $ball['kind'] = $kind;
        $ball['steps_left'] = $steps;
        $ball['steps_total'] = $steps;
        $ball['height'] = max(0.0, $height);
        $ball['progress'] = 0.0;
        $spatial['ball'] = $ball;
        return $spatial;
    }

    /** Fait avancer le ballon et retourne aussi les informations de réception. */
    public function moveBall(array $spatial): array
    {
        $completed = null;
        $ball = &$spatial['ball'];
        if (!$ball['in_transit']) {
            $ownerId = (int)$ball['owner_id'];
            $side = (string)$ball['side'];
            $owner = $spatial['players'][$side][$ownerId] ?? null;
            if ($owner !== null) {
                // Le ballon reste au pied du porteur et suit donc sa course de manière continue.
                $ball['x'] = $owner['x'];
                $ball['y'] = $owner['y'];
                $ball['z'] = 0.0;
            }
            unset($ball);
            return ['spatial' => $spatial, 'completed' => null];
        }

        $stepsLeft = max(0, (int)$ball['steps_left'] - 1);
        $total = max(1, (int)$ball['steps_total']);
        $progress = 1 - $stepsLeft / $total;
        $ball['steps_left'] = $stepsLeft;
        $ball['progress'] = $progress;
        $ball['x'] = (float)$ball['start_x'] + ((float)$ball['target_x'] - (float)$ball['start_x']) * $progress;
        $ball['y'] = (float)$ball['start_y'] + ((float)$ball['target_y'] - (float)$ball['start_y']) * $progress;
        $ball['z'] = sin(M_PI * $progress) * (float)$ball['height'];

        if ($stepsLeft === 0) {
            $completed = [
                'kind' => (string)$ball['kind'],
                'target_id' => (int)$ball['target_id'],
                'target_side' => (string)$ball['target_side'],
                'previous_owner_id' => (int)$ball['owner_id'],
                'previous_side' => (string)$ball['side'],
            ];
            $ball['owner_id'] = (int)$ball['target_id'];
            $ball['side'] = (string)$ball['target_side'];
            $ball['target_id'] = null;
            $ball['target_side'] = null;
            $ball['in_transit'] = false;
            $ball['z'] = 0.0;
        }
        unset($ball);
        return ['spatial' => $spatial, 'completed' => $completed];
    }

    public function distance(array $first, array $second): float
    {
        return hypot((float)$first['x'] - (float)$second['x'], (float)$first['y'] - (float)$second['y']);
    }
}

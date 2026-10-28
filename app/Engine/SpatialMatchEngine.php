<?php

declare(strict_types=1);

namespace App\Engine;

use App\Core\Config;
use App\Services\PlayerFactory;

/** Orchestration spatiale : dix décisions par minute, rendues ensuite à 60 FPS. */
final class SpatialMatchEngine
{
    public function __construct(
        private readonly Config $config,
        private readonly TacticalEngine $tactical,
        private readonly AIEngine $ai,
        private readonly PhysicsEngine $physics,
        private readonly CommentaryEngine $commentary,
    ) {
    }

    public function initialize(array $state): array
    {
        $homePositions = $this->tactical->initialPositions($state, 'home');
        $awayPositions = $this->tactical->initialPositions($state, 'away');
        $ownerId = $this->kickoffPlayer($state, 'home');
        $owner = $homePositions[$ownerId];
        $state['spatial'] = [
            'players' => ['home' => $homePositions, 'away' => $awayPositions],
            'ball' => [
                'x' => $owner['x'], 'y' => $owner['y'], 'z' => 0.0,
                'owner_id' => $ownerId, 'side' => 'home', 'target_id' => null, 'target_side' => null,
                'in_transit' => false, 'kind' => 'control', 'steps_left' => 0, 'steps_total' => 0,
            ],
            'timeline' => [], 'restart' => null, 'dribble' => null,
            'commentary_minute' => -1, 'commentary_count' => 0, 'last_pass' => null,
        ];
        $state['spatial']['timeline'] = [$this->snapshot($state, 0)];
        return $state;
    }

    public function advanceMinute(array $state): array
    {
        if (!isset($state['spatial'])) {
            return $this->initialize($state);
        }
        $state['spatial']['timeline'] = [];
        $state['spatial']['commentary_count'] = 0;
        $state['spatial']['commentary_minute'] = (int)$state['minute'];
        $state['spatial']['dribble'] = null;
        for ($tick = 0; $tick < 10; $tick++) {
            $state = $this->normalTick($state);
            $state['spatial']['timeline'][] = $this->snapshot($state, $tick);
        }
        return $state;
    }

    /** Transition progressive après un changement de formation ou un remplacement. */
    public function synchronize(array $state, ?array $substitution = null): array
    {
        if (!isset($state['spatial'])) {
            return $this->initialize($state);
        }
        $before = $state['spatial']['players'];
        $after = ['home' => [], 'away' => []];
        foreach (['home', 'away'] as $side) {
            $targets = $this->tactical->initialPositions($state, $side);
            foreach ($targets as $playerId => $target) {
                if (!empty($state['sides'][$side]['lineup'][$playerId]['sent_off'])) continue;
                $after[$side][$playerId] = $before[$side][$playerId] ?? $target;
            }
        }
        if ($substitution !== null) {
            $side = $substitution['side'];
            $in = (int)$substitution['in'];
            $out = (int)$substitution['out'];
            $exit = $before[$side][$out] ?? $this->tactical->initialPositions($state, $side)[$in];
            $after[$side][$in] = ['x' => $exit['x'], 'y' => $exit['y'] < 34 ? -3.0 : 71.0];
            $state['spatial']['departed'] = ['id' => $out, 'side' => $side, 'position' => $exit];
            if ((int)$state['spatial']['ball']['owner_id'] === $out || (int)$state['spatial']['ball']['target_id'] === $out) {
                $state['spatial']['ball']['owner_id'] = $in;
                $state['spatial']['ball']['side'] = $side;
                $state['spatial']['ball']['target_id'] = null;
                $state['spatial']['ball']['target_side'] = null;
                $state['spatial']['ball']['in_transit'] = false;
                $state['spatial']['ball']['kind'] = 'control';
            }
        }
        $state['spatial']['players'] = $after;
        $state['spatial']['timeline'] = [];
        $start = $before;
        for ($tick = 0; $tick < 8; $tick++) {
            $progress = ($tick + 1) / 8;
            foreach (['home', 'away'] as $side) {
                $targets = $this->tactical->targets($state, $side);
                foreach ($after[$side] as $playerId => $targetPosition) {
                    if (!empty($state['sides'][$side]['lineup'][$playerId]['sent_off'])) continue;
                    $origin = $start[$side][$playerId] ?? $after[$side][$playerId];
                    if ($substitution !== null && $side === $substitution['side'] && (int)$playerId === (int)$substitution['in']) {
                        $origin = $after[$side][$playerId];
                    }
                    $state['spatial']['players'][$side][$playerId] = [
                        'x' => $origin['x'] + ($targets[$playerId]['x'] - $origin['x']) * $progress,
                        'y' => $origin['y'] + ($targets[$playerId]['y'] - $origin['y']) * $progress,
                    ];
                }
            }
            if (isset($state['spatial']['departed'])) {
                $departed = &$state['spatial']['departed'];
                $departed['position']['y'] += (($departed['position']['y'] < 34 ? -3.0 : 71.0) - $departed['position']['y']) * 0.35;
                unset($departed);
            }
            $state = $this->attachBall($state);
            $state['spatial']['timeline'][] = $this->snapshot($state, $tick);
        }
        unset($state['spatial']['departed']);
        $ballSide = (string)$state['spatial']['ball']['side'];
        $ownerId = (int)$state['spatial']['ball']['owner_id'];
        if (!isset($state['spatial']['players'][$ballSide][$ownerId])) {
            $state['spatial']['ball']['owner_id'] = $this->kickoffPlayer($state, $ballSide);
            $state['spatial']['ball']['target_id'] = null;
            $state['spatial']['ball']['target_side'] = null;
            $state['spatial']['ball']['in_transit'] = false;
        }
        return $this->attachBall($state);
    }

    public function current(array $state): array
    {
        if (!isset($state['spatial'])) {
            return [];
        }
        $timeline = $state['spatial']['timeline'];
        return $timeline === [] ? $this->snapshot($state, 0) : $timeline[array_key_last($timeline)];
    }

    private function normalTick(array $state): array
    {
        if (isset($state['spatial']['restart'])) {
            return $this->restartTick($state);
        }
        $targets = ['home' => $this->tactical->targets($state, 'home'), 'away' => $this->tactical->targets($state, 'away')];
        if (isset($state['spatial']['dribble'])) {
            $dribble = $state['spatial']['dribble'];
            $targets[$dribble['side']][(int)$dribble['player_id']] = $dribble['target'];
        }
        $state = $this->physics->movePlayers($state, $targets);
        $movement = $this->physics->moveBall($state['spatial']);
        $state['spatial'] = $movement['spatial'];
        if ($movement['completed'] !== null) {
            $state = $this->completeBallAction($state, $movement['completed']);
        }
        if (isset($state['spatial']['restart']) || $state['spatial']['ball']['in_transit']) {
            return $state;
        }

        $side = (string)$state['spatial']['ball']['side'];
        $ownerId = (int)$state['spatial']['ball']['owner_id'];
        if (!isset($state['spatial']['players'][$side][$ownerId])) {
            return $state;
        }
        $position = $state['spatial']['players'][$side][$ownerId];
        $pressure = $this->ai->nearestOpponentDistance($state, $side === 'home' ? 'away' : 'home', $position);
        $decision = $this->ai->decide($state, $side, $ownerId, $pressure);
        $state['spatial']['dribble'] = null;

        if ($decision['action'] === 'shoot') {
            return $this->shoot($state, $side, $ownerId, (float)$decision['goal_distance'], false);
        }
        if (in_array($decision['action'], ['pass', 'long_pass', 'cross'], true)) {
            $kind = (string)$decision['action'];
            $state['stats'][$side]['passes']++;
            if ($kind === 'cross') {
                $state['stats'][$side]['crosses']++;
            }
            $steps = max(1, min(3, (int)ceil((float)$decision['distance'] / 18)));
            $height = $kind === 'cross' ? 3.0 : ($kind === 'long_pass' ? 2.0 : 0.25);
            $offsidePosition = in_array($kind,['long_pass','cross'],true) && $decision['target_side']===$side && $this->isOffside($state,$side,$position,$decision['target']);
            $receiver = $state['sides'][$side]['pool'][(int)$decision['target_id']] ?? [];
            $passer = $state['sides'][$side]['pool'][$ownerId] ?? [];
            $timing = ($this->ai->attribute($receiver,'positioning') + $this->ai->attribute($passer,'vision')) / 2;
            $style = (string)$state['sides'][$side]['tactic']['playing_style'];
            $offsideRisk = max(0.015, min(0.16, (112 - $timing) / 650 + (in_array($style,['direct','long_ball','counter'],true) ? 0.025 : ($style==='possession' ? -0.015 : 0.0))));
            $state['spatial'] = $this->physics->kick($state['spatial'], (int)$decision['target_id'], (string)$decision['target_side'], $decision['target'], $kind, $steps, $height);
            $state['spatial']['last_pass'] = [
                'player_id'=>$ownerId, 'side'=>$side, 'minute'=>(int)$state['minute'],
                'target_id'=>(int)$decision['target_id'],
                'offside'=>$offsidePosition && $this->random() < $offsideRisk,
            ];
            return $state;
        }
        if ($decision['action'] === 'dribble') {
            $state['stats'][$side]['dribbles']++;
            $state['spatial']['dribble'] = ['side' => $side, 'player_id' => $ownerId, 'target' => $decision['target']];
            $opponentSide = $side === 'home' ? 'away' : 'home';
            $opponentId = $this->nearestPlayer($state, $opponentSide, $position);
            $opponent = $state['sides'][$opponentSide]['pool'][$opponentId] ?? [];
            $player = $state['sides'][$side]['pool'][$ownerId] ?? [];
            $attack = $this->ai->attribute($player, 'dribbling') + $this->ai->attribute($player, 'pace') * 0.45;
            $defense = $this->ai->attribute($opponent, 'tackling') + $this->ai->attribute($opponent, 'positioning') * 0.45;
            $success = $attack + $this->random(-20, 20) >= $defense;
            $tackling = $this->ai->attribute($opponent, 'tackling');
            $foulRisk = max(0.10, min(0.38, 0.20 + (60 - $tackling) / 220 + (($state['sides'][$opponentSide]['tactic']['pressing'] ?? 'medium') === 'high' ? 0.05 : 0.0)));
            $foul = !$success && $opponentId > 0 && $this->random() < $foulRisk;
            if ($foul) {
                $state = $this->commitFoul($state, $opponentSide, $opponentId, $side, $ownerId);
                if ($this->insidePenaltyArea($side, $position)) {
                    $takerId = (int)($state['sides'][$side]['tactic']['penalty_taker_id'] ?? 0);
                    if (!isset($state['spatial']['players'][$side][$takerId])) $takerId = $ownerId;
                    return $this->shoot($state, $side, $takerId, 11.0, true);
                }
                $state['spatial']['restart'] = ['remaining'=>4,'side'=>$side,'reason'=>'free_kick','initialized'=>false,'position'=>['x'=>(float)$position['x'],'y'=>(float)$position['y']]];
                return $state;
            }
            if (!$success && $opponentId > 0) {
                $state['spatial'] = $this->physics->kick($state['spatial'], $opponentId, $opponentSide, $state['spatial']['players'][$opponentSide][$opponentId], 'tackle', 1, 0.0);
                $state['stats'][$opponentSide]['tackles']++;
                $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'tackle', $opponentSide, $this->commentary->name($state,$opponentSide,$opponentId) . ' remporte son duel.', $opponentId, $ownerId);
            } elseif ($success && $this->random() < 0.16) {
                $state['new_events'][] = $this->commentary->dribble($state, $side, $ownerId, true);
            }
        }
        return $state;
    }

    private function commitFoul(array $state, string $foulingSide, int $foulerId, string $attackingSide, int $victimId): array
    {
        $state['stats'][$foulingSide]['fouls']++;
        $foulerName = $this->commentary->name($state, $foulingSide, $foulerId);
        $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'foul', $foulingSide, 'Faute de ' . $foulerName . '.', $foulerId, $victimId);
        $fouler = $state['sides'][$foulingSide]['pool'][$foulerId] ?? [];
        $booking = $this->random() < max(0.10, min(0.34, 0.18 + (55 - $this->ai->attribute($fouler,'tackling')) / 260));
        $straightRed = $this->random() < 0.006;
        if ($booking || $straightRed) {
            $state['sides'][$foulingSide]['lineup'][$foulerId]['cards'] = (int)($state['sides'][$foulingSide]['lineup'][$foulerId]['cards'] ?? 0) + 1;
            $secondYellow = !$straightRed && (int)$state['sides'][$foulingSide]['lineup'][$foulerId]['cards'] >= 2;
            if ($straightRed || $secondYellow) {
                if ($secondYellow) $state['stats'][$foulingSide]['yellow']++;
                $state['stats'][$foulingSide]['red']++;
                $state['sides'][$foulingSide]['lineup'][$foulerId]['sent_off'] = true;
                unset($state['spatial']['players'][$foulingSide][$foulerId]);
                $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'red_card', $foulingSide, ($secondYellow ? 'Second carton jaune : ' : 'Carton rouge pour ') . $foulerName . ' !', $foulerId);
            } else {
                $state['stats'][$foulingSide]['yellow']++;
                $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'yellow_card', $foulingSide, 'Carton jaune pour ' . $foulerName . '.', $foulerId);
            }
        }
        $victim = $state['sides'][$attackingSide]['pool'][$victimId] ?? [];
        $physicalFatigue = (float)($victim['fatigue'] ?? 0) + 100 - (float)$state['sides'][$attackingSide]['energy'];
        if ($this->random() < 0.010 + max(0.0, $physicalFatigue) / 3000) {
            $days = random_int(3, 42);
            $state['stats'][$attackingSide]['injuries']++;
            $state['sides'][$attackingSide]['lineup'][$victimId]['injured'] = true;
            $victimName = $this->commentary->name($state, $attackingSide, $victimId);
            $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'injury', $attackingSide, $victimName . ' se blesse sur le contact et pourrait être indisponible.', $victimId, $foulerId, ['days'=>$days]);
        }
        return $state;
    }

    private function insidePenaltyArea(string $attackingSide, array $position): bool
    {
        $inDepth = $attackingSide === 'home' ? (float)$position['x'] >= 88.5 : (float)$position['x'] <= 16.5;
        return $inDepth && abs((float)$position['y'] - 34) <= 20.16;
    }

    private function shoot(array $state, string $side, int $playerId, float $distance, bool $penalty = false): array
    {
        $player = $state['sides'][$side]['pool'][$playerId] ?? [];
        $opponentSide = $side === 'home' ? 'away' : 'home';
        $goalkeeperId = $this->goalkeeper($state, $opponentSide);
        $goalkeeper = $state['sides'][$opponentSide]['pool'][$goalkeeperId] ?? [];
        $shooting = $this->ai->attribute($player, 'shooting');
        $composure = $this->ai->attribute($player, 'composure');
        $reflexes = $this->ai->attribute($goalkeeper, 'reflexes');
        $weatherPenalty = (float)($state['weather']['pass_penalty'] ?? 0.0);
        $goalProbability = $penalty
            ? 0.58 + $shooting / 600 + $composure / 800 - $reflexes / 1000 - $weatherPenalty * 0.10
            : 0.010 + $shooting / 1200 + $composure / 1400 - $distance / 300 + (75 - $reflexes) / 800 - $weatherPenalty * 0.35;
        $goalProbability = $penalty ? max(0.58, min(0.88, $goalProbability)) : max(0.035, min(0.48, $goalProbability));
        $goal = $this->random() < $goalProbability;
        $onTarget = $goal || $this->random() < ($penalty ? 0.88 : max(0.25, 0.80 - $distance / 55 + $shooting / 250));
        $name = $this->commentary->name($state, $side, $playerId);
        $assist = null;
        $state['stats'][$side]['shots']++;
        $state['stats'][$side]['chances']++;
        if ($onTarget) $state['stats'][$side]['on_target']++;
        if ($goal) {
            $state[$side . '_score']++;
            $lastPass = $state['spatial']['last_pass'];
            if (!$penalty && is_array($lastPass) && $lastPass['side'] === $side && (int)$lastPass['minute'] === (int)$state['minute'] && (int)$lastPass['player_id'] !== $playerId) {
                $assist = (int)$lastPass['player_id'];
            }
        }
        $type = $penalty ? ($goal ? 'penalty_goal' : 'penalty_miss') : ($goal ? 'goal' : ($onTarget ? 'shot_on_target' : 'chance'));
        $description = match ($type) {
            'penalty_goal' => 'Penalty transformé par ' . $name . ' !',
            'penalty_miss' => $onTarget ? 'Penalty de ' . $name . ' repoussé par le gardien.' : 'Penalty manqué par ' . $name . '.',
            'goal' => 'But de ' . $name . ' !',
            'shot_on_target' => 'Frappe cadrée de ' . $name . ', le gardien intervient.',
            default => 'Frappe de ' . $name . ' à côté.',
        };
        $relatedId = $goal ? $assist : ($onTarget ? $goalkeeperId : null);
        $state['new_events'][] = $this->commentary->event((int)$state['minute'], $type, $side, $description, $playerId, $relatedId, $goal ? ['score'=>[$state['home_score'],$state['away_score']]] : ['goalkeeper_id'=>$goalkeeperId]);
        $goalX = $side === 'home' ? 105.5 : -0.5;
        $targetY = $goal ? 34 + $this->random(-4, 4) : ($onTarget ? 34 + $this->random(-3, 3) : ($this->random() < 0.5 ? 23 : 45));
        $state['spatial'] = $this->physics->kick($state['spatial'], $playerId, $side, ['x'=>$goalX,'y'=>$targetY], $penalty ? 'penalty' : 'shot', 2, 1.2);
        $state['spatial']['last_shot'] = ['side'=>$side,'goal'=>$goal,'on_target'=>$onTarget,'player_id'=>$playerId,'penalty'=>$penalty];
        return $state;
    }

    private function completeBallAction(array $state, array $completed): array
    {
        $kind = $completed['kind'];
        if (in_array($kind, ['pass', 'long_pass', 'cross'], true)) {
            $ball = $state['spatial']['ball'];
            if ((float)$ball['y'] < 0 || (float)$ball['y'] > 68) {
                $throwSide = $completed['previous_side'] === 'home' ? 'away' : 'home';
                $position = ['x'=>max(0.0,min(105.0,(float)$ball['x'])), 'y'=>(float)$ball['y'] < 0 ? 0.0 : 68.0];
                $taker = $this->nearestPlayer($state, $throwSide, $position);
                $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'throw_in', $throwSide, 'Le ballon sort en touche. ' . $this->commentary->name($state,$throwSide,$taker) . ' va le remettre en jeu.', $taker);
                $state['spatial']['restart'] = ['remaining'=>4,'side'=>$throwSide,'reason'=>'throw_in','initialized'=>false,'position'=>$position,'taker_id'=>$taker];
                return $state;
            }
            if ($completed['target_side'] === $completed['previous_side']) {
                $lastPass = $state['spatial']['last_pass'] ?? [];
                if (!empty($lastPass['offside']) && (int)$lastPass['target_id'] === (int)$completed['target_id']) {
                    $attackingSide = (string)$completed['previous_side'];
                    $defendingSide = $attackingSide === 'home' ? 'away' : 'home';
                    $state['stats'][$attackingSide]['offsides']++;
                    $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'offside', $attackingSide, $this->commentary->name($state,$attackingSide,(int)$completed['target_id']) . ' est signalé hors-jeu.', (int)$completed['target_id'], (int)$completed['previous_owner_id']);
                    $state['spatial']['restart'] = ['remaining'=>4,'side'=>$defendingSide,'reason'=>'free_kick','initialized'=>false,'position'=>['x'=>(float)$ball['x'],'y'=>(float)$ball['y']]];
                    $state['spatial']['last_pass'] = null;
                    return $state;
                }
                $state['stats'][$completed['previous_side']]['passes_completed']++;
                $event = $this->commentary->pass($state, $completed['previous_side'], (int)$completed['previous_owner_id'], (int)$completed['target_id'], $kind);
                if ($event !== null) {
                    $state['new_events'][] = $event;
                    $state['spatial']['commentary_count']++;
                }
            } else {
                $state['stats'][$completed['target_side']]['interceptions']++;
                $state['new_events'][] = $this->commentary->interception($state, $completed['target_side'], (int)$completed['target_id'], (int)$completed['previous_owner_id']);
            }
        }
        if (in_array($kind, ['shot', 'penalty'], true)) {
            $shot = $state['spatial']['last_shot'];
            $attackingSide = $shot['side'];
            $defendingSide = $attackingSide === 'home' ? 'away' : 'home';
            if ($shot['goal']) {
                $state['spatial']['restart'] = ['remaining'=>5,'side'=>$defendingSide,'reason'=>'kickoff','initialized'=>false];
            } elseif (!$shot['penalty'] && ((!$shot['on_target'] && $this->random() < 0.30) || ($shot['on_target'] && $this->random() < 0.38))) {
                $state['stats'][$attackingSide]['corners']++;
                $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'corner', $attackingSide, $shot['on_target'] ? 'Le gardien détourne la frappe : corner.' : 'Le ballon est dévié : corner.');
                $state['spatial']['restart'] = ['remaining'=>4,'side'=>$attackingSide,'reason'=>'corner','initialized'=>false,'position'=>['x'=>$attackingSide==='home'?104.8:0.2,'y'=>$this->random()<0.5?0.2:67.8]];
            } else {
                if (!$shot['on_target']) $state['new_events'][] = $this->commentary->event((int)$state['minute'], 'goal_kick', $defendingSide, 'Sortie de but pour la défense.');
                $state['spatial']['restart'] = ['remaining'=>4,'side'=>$defendingSide,'reason'=>'goal_kick','initialized'=>false];
            }
            unset($state['spatial']['last_shot']);
        }
        return $state;
    }

    private function restartTick(array $state): array
    {
        $restart = &$state['spatial']['restart'];
        $side = (string)$restart['side'];
        $reason = (string)$restart['reason'];
        if (!$restart['initialized']) {
            $position = $restart['position'] ?? match ($reason) {
                'kickoff' => ['x'=>52.5,'y'=>34.0],
                'goal_kick' => ['x'=>$side==='home'?3.0:102.0,'y'=>34.0],
                default => ['x'=>(float)$state['spatial']['ball']['x'],'y'=>(float)$state['spatial']['ball']['y']],
            };
            $ownerId = (int)($restart['taker_id'] ?? 0);
            if ($ownerId <= 0) {
                $ownerId = match ($reason) {
                    'goal_kick' => $this->goalkeeper($state, $side),
                    'kickoff' => $this->kickoffPlayer($state, $side),
                    default => $this->nearestPlayer($state, $side, $position),
                };
            }
            $restart['position'] = $position;
            $restart['taker_id'] = $ownerId;
            $state['spatial'] = $this->physics->kick($state['spatial'], $ownerId, $side, $position, 'restart', max(1,(int)$restart['remaining']), $reason==='corner'?1.5:0.4);
            $restart['initialized'] = true;
        }
        $ownerId = (int)$restart['taker_id'];
        $position = $restart['position'];
        unset($restart);
        $targets = ['home'=>$this->tactical->targets($state,'home'), 'away'=>$this->tactical->targets($state,'away')];
        if (isset($targets[$side][$ownerId])) $targets[$side][$ownerId] = $position;
        $state = $this->physics->movePlayers($state, $targets);
        $movement = $this->physics->moveBall($state['spatial']);
        $state['spatial'] = $movement['spatial'];
        $state['spatial']['restart']['remaining']--;
        if ((int)$state['spatial']['restart']['remaining'] <= 0) unset($state['spatial']['restart']);
        return $state;
    }

    private function attachBall(array $state): array
    {
        $movement = $this->physics->moveBall($state['spatial']);
        $state['spatial'] = $movement['spatial'];
        return $state;
    }

    private function goalkeeper(array $state, string $side): int
    {
        $availablePlayers = $state['spatial']['players'][$side] ?? null;
        foreach ($state['sides'][$side]['tactic']['lineup'] as $entry) {
            if ($entry['position'] === 'GK' && ($availablePlayers === null || isset($availablePlayers[(int)$entry['player_id']]))) {
                return (int)$entry['player_id'];
            }
        }
        $available = array_key_first($availablePlayers ?? []);
        return (int)($available ?? $state['sides'][$side]['tactic']['lineup'][0]['player_id']);
    }

    private function kickoffPlayer(array $state, string $side): int
    {
        $availablePlayers = $state['spatial']['players'][$side] ?? null;
        foreach ($state['sides'][$side]['tactic']['lineup'] as $entry) {
            if (PlayerFactory::group((string)$entry['position']) === 'MID' && ($availablePlayers === null || isset($availablePlayers[(int)$entry['player_id']]))) {
                return (int)$entry['player_id'];
            }
        }
        $available = array_key_first($availablePlayers ?? []);
        return (int)($available ?? $state['sides'][$side]['tactic']['lineup'][0]['player_id']);
    }

    private function isOffside(array $state, string $side, array $origin, array $target): bool
    {
        return $this->ai->isOffsidePosition($state, $side, $origin, $target);
    }

    private function nearestPlayer(array $state, string $side, array $position): int
    {
        $bestId = 0;
        $bestDistance = INF;
        foreach ($state['spatial']['players'][$side] as $playerId => $candidate) {
            $distance = $this->physics->distance($position, $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestId = (int)$playerId;
            }
        }
        return $bestId;
    }

    private function snapshot(array $state, int $tick): array
    {
        $players = ['home' => [], 'away' => []];
        foreach (['home', 'away'] as $side) {
            foreach ($state['spatial']['players'][$side] as $playerId => $position) {
                $players[$side][] = [(int)$playerId, round((float)$position['x'], 2), round((float)$position['y'], 2)];
            }
        }
        if (isset($state['spatial']['departed'])) {
            $departed = $state['spatial']['departed'];
            $players[$departed['side']][] = [(int)$departed['id'], round((float)$departed['position']['x'], 2), round((float)$departed['position']['y'], 2), 1];
        }
        $ball = $state['spatial']['ball'];
        return [
            'minute' => (int)$state['minute'], 'tick' => $tick,
            'score' => [(int)$state['home_score'], (int)$state['away_score']],
            'restart' => $state['spatial']['restart']['reason'] ?? null,
            'players' => $players,
            'ball' => [round((float)$ball['x'], 2), round((float)$ball['y'], 2), round((float)$ball['z'], 2), (int)$ball['owner_id'], $ball['in_transit'] ? 1 : 0],
        ];
    }

    private function random(float $minimum = 0.0, float $maximum = 1.0): float
    {
        if ($minimum === 0.0 && $maximum === 1.0) {
            return random_int(0, 1000000) / 1000000;
        }
        return random_int((int)ceil($minimum), (int)floor($maximum));
    }
}

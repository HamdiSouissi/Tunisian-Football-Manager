<?php

declare(strict_types=1);

namespace App\Engine;

use App\Contracts\MatchEngineInterface;
use App\Core\Config;
use App\Services\PlayerFactory;
use RuntimeException;

/** Moteur minute par minute sans accès à la base ni au HTTP. */
final class TacticalMatchEngine implements MatchEngineInterface
{
    private readonly SpatialMatchEngine $spatial;
    private readonly StatisticsEngine $statistics;
    private readonly WeatherEngine $weather;

    public function __construct(
        private readonly Config $config,
        ?SpatialMatchEngine $spatial = null,
        ?StatisticsEngine $statistics = null,
        ?WeatherEngine $weather = null,
    ) {
        $this->spatial = $spatial ?? new SpatialMatchEngine($config, new TacticalEngine(), new AIEngine(), new PhysicsEngine(), new CommentaryEngine());
        $this->statistics = $statistics ?? new StatisticsEngine();
        $this->weather = $weather ?? new WeatherEngine();
    }

    public function initialize(array $homePlayers, array $awayPlayers, array $homeTactic, array $awayTactic, array $options = []): array
    {
        $state = [
            'minute' => 0, 'status' => 'live', 'home_score' => 0, 'away_score' => 0, 'new_events' => [],
            'sides' => [
                'home' => $this->buildSide($homePlayers, $homeTactic, (float)($options['home_ai_strength'] ?? 1.0)),
                'away' => $this->buildSide($awayPlayers, $awayTactic, (float)($options['away_ai_strength'] ?? 1.0)),
            ],
            'stats' => [
                'home' => $this->emptyStats(), 'away' => $this->emptyStats(),
            ],
            'home_advantage' => (float)($options['home_advantage'] ?? $this->config->get('game.match.home_advantage',1.07)),
            'weather' => $this->weather->create($options),
            'added_time' => ['first' => random_int(1, 3), 'second' => random_int(2, 5)],
            'visual_tick_ms' => (int)$this->config->get('game.match.visual_tick_ms',100),
        ];
        $state = $this->spatial->initialize($state);
        return $this->statistics->initialize($state);
    }

    public function advance(array $state, bool $ignoreHalftime = false): array
    {
        if (in_array($state['status'], ['finished','halftime'], true)) {
            $state['new_events'] = [];
            return $state;
        }
        $state['new_events'] = [];
        $regularMinutes = (int)$this->config->get('game.match.minutes',90);
        $regularHalftime = (int)$this->config->get('game.match.halftime',45);
        $halftime = $regularHalftime + (int)($state['added_time']['first'] ?? 1);
        $fullTime = $regularMinutes + (int)($state['added_time']['second'] ?? 2);
        $state['minute'] = min($fullTime, (int)$state['minute'] + 1);
        $minute = (int)$state['minute'];
        $attackSide = (string)($state['spatial']['ball']['side'] ?? 'home');
        $state['stats'][$attackSide]['possession']++;
        $this->applyEnergy($state);

        // Toutes les actions ordinaires et disciplinaires naissent de la simulation spatiale.
        $state = $this->spatial->advanceMinute($state);
        $state = $this->statistics->record($state, $state['new_events']);

        if ($minute === $halftime && !$ignoreHalftime) {
            $state['status'] = 'halftime';
            $state['new_events'][] = $this->event($minute,'info',null,'Mi-temps. Vous pouvez ajuster la tactique et effectuer vos remplacements.');
        } elseif ($minute >= $fullTime) {
            $state['status'] = 'finished';
            $state['new_events'][] = $this->event($minute,'info',null,'Coup de sifflet final.');
            $state = $this->statistics->finalize($state);
        }
        return $state;
    }

    public function substitute(array $state, string $side, int $outPlayerId, int $inPlayerId): array
    {
        if (!isset($state['sides'][$side])) {
            throw new RuntimeException('Côté invalide.');
        }
        $current = $state['sides'][$side];
        if (!empty($current['lineup'][$outPlayerId]['sent_off'])) {
            throw new RuntimeException('Un joueur expulsé ne peut pas être remplacé.');
        }
        if ((int)$current['substitutions'] >= (int)$this->config->get('game.match.max_substitutions',5)) {
            throw new RuntimeException('Nombre maximal de remplacements atteint.');
        }
        $found = false;
        foreach ($current['tactic']['lineup'] as &$entry) {
            if ((int)$entry['player_id'] === $outPlayerId) {
                $entry['player_id'] = $inPlayerId;
                $found = true;
                break;
            }
        }
        unset($entry);
        if (!$found || !in_array($inPlayerId,array_map(static fn($e)=>(int)$e['player_id'],$current['tactic']['substitutes']),true)) {
            throw new RuntimeException('Le joueur sortant ou entrant n’est pas dans la composition.');
        }
        $current['tactic']['substitutes'] = array_values(array_filter($current['tactic']['substitutes'],static fn($e)=>(int)$e['player_id']!==$inPlayerId));
        $outName = $current['lineup'][$outPlayerId]['name'] ?? 'Joueur';
        $inName = $current['pool'][$inPlayerId]['first_name'].' '.$current['pool'][$inPlayerId]['last_name'];
        $rebuilt = $this->buildSide(array_values($current['pool']),$current['tactic'],(float)$current['ai_strength']);
        $rebuilt = $this->restoreSideState($rebuilt, $current);
        $rebuilt['substitutions'] = (int)$current['substitutions']+1;
        $state['sides'][$side] = $rebuilt;
        $state['new_events'][] = $this->event((int)$state['minute'],'substitution',$side,sprintf('Remplacement : %s cède sa place à %s.',$outName,$inName),$inPlayerId,$outPlayerId);
        $state = $this->statistics->synchronize($state);
        return $this->spatial->synchronize($state, ['side' => $side, 'out' => $outPlayerId, 'in' => $inPlayerId]);
    }

    public function changeTactic(array $state, string $side, array $tactic): array
    {
        if (!isset($state['sides'][$side]) || $state['status'] === 'finished') {
            throw new RuntimeException('Impossible de modifier cette tactique.');
        }
        $current = $state['sides'][$side];
        $rebuilt = $this->buildSide(array_values($current['pool']),$tactic,(float)$current['ai_strength']);
        $rebuilt = $this->restoreSideState($rebuilt, $current);
        $rebuilt['substitutions'] = (int)$current['substitutions'];
        $state['sides'][$side] = $rebuilt;
        $state = $this->statistics->synchronize($state);
        $state = $this->spatial->synchronize($state);
        $formationChanged = (string)($current['tactic']['formation'] ?? '') !== (string)($tactic['formation'] ?? '');
        $description = $formationChanged
            ? 'Nouvelle organisation en ' . $tactic['formation'] . ' et consignes appliquées.'
            : 'Nouvelles consignes tactiques appliquées.';
        $state['new_events'][] = $this->event((int)$state['minute'],'info',$side,$description);
        return $state;
    }

    /** Un ajustement ne doit ni reposer l’équipe ni effacer cartons et blessures. */
    private function restoreSideState(array $rebuilt, array $current): array
    {
        $rebuilt['energy'] = (float)($current['energy'] ?? 100.0);
        foreach ($rebuilt['lineup'] as $playerId => &$entry) {
            if (!isset($current['lineup'][$playerId])) {
                continue;
            }
            $entry['cards'] = (int)($current['lineup'][$playerId]['cards'] ?? 0);
            $entry['injured'] = (bool)($current['lineup'][$playerId]['injured'] ?? false);
            $entry['sent_off'] = (bool)($current['lineup'][$playerId]['sent_off'] ?? false);
        }
        unset($entry);
        return $rebuilt;
    }

    private function buildSide(array $players, array $tactic, float $aiStrength): array
    {
        $pool = [];
        foreach ($players as $player) {
            $pool[(int)$player['id']] = $player;
        }
        $lineup = []; $fitSum = 0.0; $attack = 0.0; $defense = 0.0; $midfield = 0.0;
        foreach ($tactic['lineup'] as $entry) {
            $id = (int)$entry['player_id'];
            if (!isset($pool[$id])) {
                throw new RuntimeException('Un titulaire ne fait pas partie de l’effectif.');
            }
            $player = $pool[$id];
            $fit = $this->positionalFit($player,(string)$entry['position']);
            $effective = (float)$player['overall_rating'] * $fit * (.90+(int)$player['form']/500) * (.95+(int)$player['morale']/1000) * (1-(int)$player['fatigue']/240);
            $lineup[$id] = ['id'=>$id,'name'=>$player['first_name'].' '.$player['last_name'],'position'=>$entry['position'],'effective'=>$effective,'fit'=>$fit,'cards'=>0,'injured'=>false];
            $fitSum += $fit;
            $group = PlayerFactory::group((string)$entry['position']);
            if (in_array($group,['ATT','WING'],true)) { $attack += $effective*1.2; $midfield += $effective*.35; }
            elseif ($group === 'MID') { $midfield += $effective; $attack += $effective*.45; $defense += $effective*.35; }
            elseif ($group === 'DEF') { $defense += $effective*1.15; $midfield += $effective*.25; }
            else { $defense += $effective*1.4; }
            // Les consignes et les rôles modifient réellement les forces du onze.
            $instruction = (string)($entry['instruction'] ?? 'normal');
            $role = (string)($entry['role'] ?? 'support');
            if ($instruction === 'attack') { $attack += $effective*.22; $defense -= $effective*.08; }
            if ($instruction === 'stay_back') { $defense += $effective*.18; $attack -= $effective*.08; }
            if ($role === 'attack') { $attack += $effective*.15; $defense -= $effective*.06; }
            if ($role === 'defend') { $defense += $effective*.15; $attack -= $effective*.05; }
            if ($role === 'playmaker') { $midfield += $effective*.22; $attack += $effective*.05; }
            if ($role === 'target') { $attack += $effective*.18; $midfield -= $effective*.04; }
        }
        if (count($lineup) !== 11) {
            throw new RuntimeException('La composition doit contenir 11 joueurs différents.');
        }
        $attack=max(1.0,$attack);$defense=max(1.0,$defense);$midfield=max(1.0,$midfield);
        $countAttack = max(1,count(array_filter($tactic['lineup'],fn($e)=>in_array(PlayerFactory::group((string)$e['position']),['ATT','WING'],true))));
        $cohesion = $fitSum/11;
        $mentality = ['very_defensive'=>[.78,1.14],'defensive'=>[.90,1.07],'balanced'=>[1,1],'attacking'=>[1.12,.93],'very_attacking'=>[1.24,.84]][$tactic['mentality']] ?? [1,1];
        $attack = ($attack/$countAttack) * $mentality[0] * $cohesion;
        $defense = ($defense/6) * $mentality[1] * $cohesion;
        $midfield = ($midfield/6) * $cohesion;
        switch ($tactic['playing_style']) {
            case 'possession': $midfield*=1.10; $attack*=.98; break;
            case 'counter': $attack*=1.07; $defense*=1.03; break;
            case 'wings': $attack*=1.06; break;
            case 'long_ball': case 'direct': $attack*=1.04; $midfield*=.96; break;
        }
        if ($tactic['pressing']==='high') { $midfield*=1.06; $defense*=.98; }
        if ($tactic['pressing']==='low') { $defense*=1.04; $midfield*=.97; }
        if ($tactic['tempo']==='fast') { $attack*=1.04; $defense*=.98; }
        if ($tactic['tempo']==='slow') { $midfield*=1.03; $attack*=.97; }
        return ['pool'=>$pool,'lineup'=>$lineup,'tactic'=>$tactic,'ratings'=>['attack'=>$attack,'defense'=>$defense,'midfield'=>$midfield],
            'cohesion'=>$cohesion,'energy'=>100.0,'substitutions'=>0,'ai_strength'=>$aiStrength,
            'base_ratings'=>['attack'=>$attack*$aiStrength,'defense'=>$defense*$aiStrength,'midfield'=>$midfield*$aiStrength]];
    }

    private function positionalFit(array $player,string $position): float
    {
        if($player['primary_position']===$position)return 1.0;
        $secondary=json_decode((string)($player['secondary_positions']??'[]'),true)?:[];
        if(in_array($position,$secondary,true))return .91;
        if($position==='GK'||$player['primary_position']==='GK')return .35;
        return PlayerFactory::group($player['primary_position'])===PlayerFactory::group($position)?.82:.68;
    }

    private function applyEnergy(array &$state): void
    {
        $weatherCost = (float)($state['weather']['energy_cost'] ?? 1.0);
        foreach(['home','away'] as $side){
            $pressing=$state['sides'][$side]['tactic']['pressing'];
            $loss=$pressing==='high'?.055:($pressing==='low'?.018:.032);
            $state['sides'][$side]['energy']=max(55,(float)$state['sides'][$side]['energy']-$loss*$weatherCost);
        }
    }

    private function emptyStats(): array
    {
        return ['possession'=>0,'chances'=>0,'shots'=>0,'on_target'=>0,'passes'=>0,'passes_completed'=>0,'crosses'=>0,'corners'=>0,'dribbles'=>0,'tackles'=>0,'interceptions'=>0,'offsides'=>0,'fouls'=>0,'yellow'=>0,'red'=>0,'injuries'=>0];
    }
    private function event(int $minute,string $type,?string $side,string $description,?int $playerId=null,?int $relatedId=null,array $data=[]): array
    { return ['minute'=>$minute,'type'=>$type,'side'=>$side,'description'=>$description,'player_id'=>$playerId,'related_player_id'=>$relatedId,'data'=>$data]; }
}

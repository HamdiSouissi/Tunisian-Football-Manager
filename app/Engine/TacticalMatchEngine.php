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
    public function __construct(private readonly Config $config)
    {
    }

    public function initialize(array $homePlayers, array $awayPlayers, array $homeTactic, array $awayTactic, array $options = []): array
    {
        return [
            'minute' => 0, 'status' => 'live', 'home_score' => 0, 'away_score' => 0, 'new_events' => [],
            'sides' => [
                'home' => $this->buildSide($homePlayers, $homeTactic, (float)($options['home_ai_strength'] ?? 1.0)),
                'away' => $this->buildSide($awayPlayers, $awayTactic, (float)($options['away_ai_strength'] ?? 1.0)),
            ],
            'stats' => [
                'home' => $this->emptyStats(), 'away' => $this->emptyStats(),
            ],
            'home_advantage' => (float)($options['home_advantage'] ?? $this->config->get('game.match.home_advantage',1.07)),
        ];
    }

    public function advance(array $state, bool $ignoreHalftime = false): array
    {
        if (in_array($state['status'], ['finished','halftime'], true)) {
            $state['new_events'] = [];
            return $state;
        }
        $state['new_events'] = [];
        $state['minute'] = min((int)$this->config->get('game.match.minutes',90), (int)$state['minute'] + 1);
        $minute = (int)$state['minute'];
        $homeStrength = $this->strength($state,'home') * (float)$state['home_advantage'];
        $awayStrength = $this->strength($state,'away');
        $possessionHome = $homeStrength / max(1,$homeStrength+$awayStrength);
        $possessionSide = $this->random() < $possessionHome ? 'home' : 'away';
        $state['stats'][$possessionSide]['possession']++;
        $this->applyEnergy($state);

        $attackSide = $this->random() < $possessionHome ? 'home' : 'away';
        $defenseSide = $attackSide === 'home' ? 'away' : 'home';
        $attack = $this->strength($state,$attackSide) * $this->styleMatchup($state,$attackSide);
        $defense = $this->strength($state,$defenseSide);
        $ratio = max(.55,min(1.65,$attack/max(1,$defense)));
        $chanceProbability = (float)$this->config->get('game.match.base_chance_probability',.115) * $ratio;

        if ($this->random() < $chanceProbability) {
            $state = $this->chance($state,$attackSide,$ratio);
        } elseif ($this->random() < .075) {
            $state = $this->foul($state,$defenseSide);
        } elseif ($this->random() < (float)$this->config->get('game.match.injury_base_probability',.0035)) {
            $state = $this->injury($state,$attackSide);
        }

        if ($minute === (int)$this->config->get('game.match.halftime',45) && !$ignoreHalftime) {
            $state['status'] = 'halftime';
            $state['new_events'][] = $this->event($minute,'info',null,'Mi-temps. Vous pouvez ajuster la tactique et effectuer vos remplacements.');
        } elseif ($minute >= (int)$this->config->get('game.match.minutes',90)) {
            $state['status'] = 'finished';
            $state['new_events'][] = $this->event($minute,'info',null,'Coup de sifflet final.');
        }
        return $state;
    }

    public function substitute(array $state, string $side, int $outPlayerId, int $inPlayerId): array
    {
        if (!isset($state['sides'][$side])) {
            throw new RuntimeException('Côté invalide.');
        }
        $current = $state['sides'][$side];
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
        $rebuilt['substitutions'] = (int)$current['substitutions']+1;
        $state['sides'][$side] = $rebuilt;
        $state['new_events'][] = $this->event((int)$state['minute'],'substitution',$side,sprintf('Remplacement : %s cède sa place à %s.',$outName,$inName),$inPlayerId,$outPlayerId);
        return $state;
    }

    public function changeTactic(array $state, string $side, array $tactic): array
    {
        if (!isset($state['sides'][$side]) || $state['status'] === 'finished') {
            throw new RuntimeException('Impossible de modifier cette tactique.');
        }
        $current = $state['sides'][$side];
        $rebuilt = $this->buildSide(array_values($current['pool']),$tactic,(float)$current['ai_strength']);
        $rebuilt['substitutions'] = (int)$current['substitutions'];
        $state['sides'][$side] = $rebuilt;
        $state['new_events'][] = $this->event((int)$state['minute'],'info',$side,'Nouvelles consignes tactiques appliquées.');
        return $state;
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

    private function strength(array $state,string $side): float
    {
        $s = $state['sides'][$side]; $energy = .85+(float)$s['energy']/500;
        return (($s['base_ratings']['attack']*.38)+($s['base_ratings']['midfield']*.34)+($s['base_ratings']['defense']*.28))*$energy;
    }

    private function styleMatchup(array $state,string $attackSide): float
    {
        $defenseSide=$attackSide==='home'?'away':'home';
        $attackStyle=$state['sides'][$attackSide]['tactic']['playing_style'];
        $defensiveLine=$state['sides'][$defenseSide]['tactic']['defensive_line'];
        if ($attackStyle==='counter' && $defensiveLine==='high') return 1.16;
        if ($attackStyle==='possession' && $defensiveLine==='deep') return 1.07;
        if ($attackStyle==='long_ball' && $defensiveLine==='high') return 1.08;
        return 1.0;
    }

    private function chance(array $state,string $side,float $ratio): array
    {
        $state['stats'][$side]['chances']++; $state['stats'][$side]['shots']++;
        $attack = $state['sides'][$side]['base_ratings']['attack'];
        $other = $side==='home'?'away':'home'; $defense = $state['sides'][$other]['base_ratings']['defense'];
        $goalProbability=(float)$this->config->get('game.match.base_goal_probability',.245)*max(.55,min(1.6,$attack/max(1,$defense)));
        $isPenalty=$this->random()<.055;
        $isGoal=$this->random()<$goalProbability;
        $penaltyTaker=(int)($state['sides'][$side]['tactic']['penalty_taker_id']??0);
        $scorer=$isPenalty&&isset($state['sides'][$side]['lineup'][$penaltyTaker])?$penaltyTaker:$this->pickPlayer($state,$side,true);
        $scorerName=$state['sides'][$side]['lineup'][$scorer]['name'] ?? 'Joueur';
        if ($isGoal) {
            $state[$side.'_score']++;
            $state['stats'][$side]['on_target']++;
            $assist=$this->pickPlayer($state,$side,false);
            $assist=$assist===$scorer?null:$assist;
            $state['new_events'][]=$this->event((int)$state['minute'],$isPenalty?'penalty_goal':'goal',$side,
                ($isPenalty?'Penalty transformé par ':'But de ').$scorerName.' !',$scorer,$assist,['score'=>[$state['home_score'],$state['away_score']]]);
        } else {
            $onTarget=$this->random()<.55;
            if($onTarget)$state['stats'][$side]['on_target']++;
            $state['new_events'][]=$this->event((int)$state['minute'],$isPenalty?'penalty_miss':($onTarget?'shot_on_target':'chance'),$side,
                $isPenalty ? 'Penalty manqué par '.$scorerName.'.' : ($onTarget ? 'Frappe cadrée de '.$scorerName.', le gardien intervient.' : 'Occasion pour '.$scorerName.', mais le ballon passe à côté.'),$scorer);
        }
        return $state;
    }

    private function foul(array $state,string $side): array
    {
        $state['stats'][$side]['fouls']++; $player=$this->pickPlayer($state,$side,false);
        $name=$state['sides'][$side]['lineup'][$player]['name'] ?? 'Joueur';
        $card=$this->random();
        if($card<.22){
            $state['sides'][$side]['lineup'][$player]['cards']++;
            if($state['sides'][$side]['lineup'][$player]['cards']>=2 || $card<.018){
                $state['stats'][$side]['red']++;
                $state['sides'][$side]['base_ratings']['defense']*=.88;
                $state['sides'][$side]['base_ratings']['midfield']*=.92;
                $state['new_events'][]=$this->event((int)$state['minute'],'red_card',$side,'Carton rouge pour '.$name.' !',$player);
            }else{$state['stats'][$side]['yellow']++;$state['new_events'][]=$this->event((int)$state['minute'],'yellow_card',$side,'Carton jaune pour '.$name.'.',$player);}
        }else{$state['new_events'][]=$this->event((int)$state['minute'],'foul',$side,'Faute de '.$name.'.',$player);}
        return $state;
    }

    private function injury(array $state,string $side): array
    {
        $player=$this->pickPlayer($state,$side,false);$state['stats'][$side]['injuries']++;
        $state['sides'][$side]['lineup'][$player]['injured']=true;
        $days=random_int(3,42);$name=$state['sides'][$side]['lineup'][$player]['name'] ?? 'Joueur';
        $state['sides'][$side]['base_ratings']['attack']*=.98;$state['sides'][$side]['base_ratings']['defense']*=.98;
        $state['new_events'][]=$this->event((int)$state['minute'],'injury',$side,$name.' se blesse et pourrait être indisponible.',$player,null,['days'=>$days]);
        return $state;
    }

    private function pickPlayer(array $state,string $side,bool $attacker): int
    {
        $weighted=[];
        foreach($state['sides'][$side]['lineup'] as $id=>$player){
            $group=PlayerFactory::group((string)$player['position']);
            $weight=$attacker?(['ATT'=>6,'WING'=>4,'MID'=>2,'DEF'=>.25,'GK'=>.05][$group]??1):1;
            for($i=0;$i<max(1,(int)ceil($weight));$i++)$weighted[]=(int)$id;
        }
        return $weighted[array_rand($weighted)];
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
        foreach(['home','away'] as $side){$pressing=$state['sides'][$side]['tactic']['pressing'];$loss=$pressing==='high'?.055:($pressing==='low'?.018:.032);$state['sides'][$side]['energy']=max(55,(float)$state['sides'][$side]['energy']-$loss);}
    }

    private function emptyStats(): array { return ['possession'=>0,'chances'=>0,'shots'=>0,'on_target'=>0,'fouls'=>0,'yellow'=>0,'red'=>0,'injuries'=>0]; }
    private function random(): float { return random_int(0,1000000)/1000000; }
    private function event(int $minute,string $type,?string $side,string $description,?int $playerId=null,?int $relatedId=null,array $data=[]): array
    { return ['minute'=>$minute,'type'=>$type,'side'=>$side,'description'=>$description,'player_id'=>$playerId,'related_player_id'=>$relatedId,'data'=>$data]; }
}

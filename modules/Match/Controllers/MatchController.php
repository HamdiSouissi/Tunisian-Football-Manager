<?php

declare(strict_types=1);

namespace Modules\Match\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Services\GameContext;
use App\Services\MatchService;
use App\Services\TacticService;
use Throwable;

final class MatchController extends GameController
{
    public function __construct(Application $app, private readonly GameContext $context, private readonly MatchService $matches)
    {
        parent::__construct($app);
    }

    public function show(Request $request,string $save,string $fixture): Response
    {
        try {
            $data=$this->matches->fixtureForUser($this->context,(int)$fixture);
            $state=$data['match_state']?json_decode((string)$data['match_state'],true,512,JSON_THROW_ON_ERROR):null;
            $side=(int)$data['home_club_id']===$this->context->clubId()?'home':'away';
            return $this->gameView('match::show',['title'=>$data['home_name'].' - '.$data['away_name'],'active'=>'calendar','fixture'=>$data,'state'=>$state,'events'=>$this->matches->events($this->context->saveId(),(int)$fixture),'side'=>$side,'formations'=>TacticService::FORMATIONS,'initialPayload'=>$state!==null?$this->payload($state):null]);
        } catch (Throwable $exception) {
            return Response::html(e($this->exceptionMessage($exception)),404);
        }
    }

    public function start(Request $request,string $save,string $fixture): Response
    {
        try {$state=$this->matches->start($this->context,(int)$fixture);return $request->expectsJson()?$this->json($this->payload($state)):$this->redirect($this->saveUrl('matches/'.$fixture));}
        catch(Throwable $e){return $this->error($request,$e);}
    }
    public function simulate(Request $request,string $save,string $fixture): Response
    {
        try {
            $state=$this->matches->simulateForUser($this->context,(int)$fixture);
            if ($request->expectsJson()) {
                $payload = $this->payload($state);
                $payload['events'] = $this->matches->events($this->context->saveId(), (int)$fixture);
                $payload['replace_events'] = true;
                return $this->json($payload);
            }
            return $this->redirect($this->saveUrl('matches/'.$fixture));
        } catch(Throwable $e) {
            return $this->error($request,$e);
        }
    }
    public function step(Request $request,string $save,string $fixture): Response
    {
        try{return $this->json($this->payload($this->matches->step($this->context,(int)$fixture)));}
        catch(Throwable $e){return $this->error($request,$e);}
    }
    public function resume(Request $request,string $save,string $fixture): Response
    {
        try{return $this->json($this->payload($this->matches->resume($this->context,(int)$fixture)));}
        catch(Throwable $e){return $this->error($request,$e);}
    }
    public function substitution(Request $request,string $save,string $fixture): Response
    {
        try{$state=$this->matches->substitute($this->context,(int)$fixture,(int)$request->input('out_player'),(int)$request->input('in_player'));return $this->json($this->payload($state));}
        catch(Throwable $e){return $this->error($request,$e);}
    }
    public function tactic(Request $request,string $save,string $fixture): Response
    {
        try{$state=$this->matches->changeTactic($this->context,(int)$fixture,$request->all());return $this->json($this->payload($state));}
        catch(Throwable $e){return $this->error($request,$e);}
    }

    private function payload(array $state): array
    {
        $sides = [];
        $visualPlayers = [];
        foreach (['home', 'away'] as $side) {
            $sideState = $state['sides'][$side];
            $lineup = [];
            $lineupFatigue = 0.0;
            foreach ($sideState['tactic']['lineup'] as $entry) {
                $playerId = (int)$entry['player_id'];
                $player = $sideState['lineup'][$playerId] ?? [];
                $number = (int)($entry['slot'] ?? 0) + 1;
                $rating = (float)($state['player_ratings'][$side][$playerId] ?? 6.0);
                $lineup[] = [
                    'id' => $playerId, 'name' => (string)($player['name'] ?? 'Joueur'),
                    'position' => (string)$entry['position'], 'cards' => (int)($player['cards'] ?? 0),
                    'injured' => (bool)($player['injured'] ?? false), 'sent_off' => (bool)($player['sent_off'] ?? false), 'number' => $number,
                    'rating' => round($rating, 1), 'fatigue' => (int)($sideState['pool'][$playerId]['fatigue'] ?? 0),
                ];
                $lineupFatigue += (float)($sideState['pool'][$playerId]['fatigue'] ?? 0);
                $visualPlayers[$playerId] = [
                    'id' => $playerId, 'side' => $side, 'name' => (string)($player['name'] ?? 'Joueur'),
                    'number' => $number, 'position' => (string)$entry['position'], 'rating' => round($rating, 1),
                ];
            }
            $substitutes = [];
            foreach ($sideState['tactic']['substitutes'] as $entry) {
                $playerId = (int)$entry['player_id'];
                $player = $sideState['pool'][$playerId] ?? null;
                if ($player !== null) {
                    $substitutes[] = [
                        'id' => $playerId, 'name' => $player['first_name'] . ' ' . $player['last_name'],
                        'position' => (string)$player['primary_position'],
                    ];
                }
            }
            foreach ($sideState['pool'] as $playerId => $player) {
                $playerId = (int)$playerId;
                $visualPlayers[$playerId] ??= [
                    'id' => $playerId, 'side' => $side,
                    'name' => trim((string)$player['first_name'] . ' ' . (string)$player['last_name']),
                    'number' => 0, 'position' => (string)$player['primary_position'],
                    'rating' => round((float)($state['player_ratings'][$side][$playerId] ?? 6.0), 1),
                ];
            }
            $appearances = [];
            foreach ($state['player_minutes'][$side] ?? [] as $playerId => $minutes) {
                if ((int)$minutes <= 0) continue;
                $player = $sideState['pool'][$playerId] ?? [];
                $appearances[] = [
                    'id'=>(int)$playerId, 'name'=>trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? '')),
                    'position'=>(string)($player['primary_position'] ?? ''), 'minutes'=>(int)$minutes,
                    'rating'=>round((float)($state['player_ratings'][$side][$playerId] ?? 6.0), 1),
                    'experience'=>(int)($state['experience_gained'][$side][$playerId] ?? 0),
                ];
            }
            $sides[$side] = [
                'lineup' => $lineup, 'substitutes' => $substitutes, 'appearances' => $appearances,
                'substitutions' => (int)$sideState['substitutions'],
                'energy' => round((float)$sideState['energy'], 1),
                'fatigue' => round(min(100, $lineupFatigue / max(1, count($lineup)) + 100 - (float)$sideState['energy']), 1),
                'tactic' => array_intersect_key($sideState['tactic'], array_flip([
                    'formation', 'mentality', 'playing_style', 'pressing', 'defensive_line', 'tempo', 'width',
                ])),
            ];
        }
        $regularMinutes = 90;
        $fullTime = $regularMinutes + (int)($state['added_time']['second'] ?? 0);
        return [
            'minute'=>$state['minute'], 'display_minute'=>$this->displayMinute($state),
            'full_time'=>$fullTime, 'status'=>$state['status'],
            'home_score'=>$state['home_score'], 'away_score'=>$state['away_score'],
            'stats'=>$state['stats'], 'events'=>$state['new_events'], 'sides'=>$sides,
            'man_of_the_match'=>$state['man_of_the_match'] ?? null,
            'visual'=>[
                'width'=>105, 'height'=>68, 'tick_ms'=>(int)($state['visual_tick_ms'] ?? 100),
                'frames'=>$state['spatial']['timeline'] ?? [], 'players'=>$visualPlayers,
                'weather'=>$state['weather'] ?? ['type'=>'clear','label'=>'Ciel dégagé','icon'=>'☀','temperature'=>24,'wind'=>8],
            ],
        ];
    }

    private function displayMinute(array $state): string
    {
        $minute = (int)$state['minute'];
        $firstHalfAdded = (int)($state['added_time']['first'] ?? 0);
        if ($minute > 45 && $minute <= 45 + $firstHalfAdded) {
            return '45+' . ($minute - 45);
        }
        if ($minute > 90) {
            return '90+' . ($minute - 90);
        }
        return (string)$minute;
    }

    private function error(Request $request,Throwable $exception): Response
    {
        return $request->expectsJson()||in_array($request->method(),['POST','PUT','PATCH'],true)?$this->json(['message'=>$this->exceptionMessage($exception)],422):Response::html(e($this->exceptionMessage($exception)),422);
    }
}

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
            return $this->gameView('match::show',['title'=>$data['home_name'].' - '.$data['away_name'],'active'=>'calendar','fixture'=>$data,'state'=>$state,'events'=>$this->matches->events($this->context->saveId(),(int)$fixture),'side'=>$side,'formations'=>TacticService::FORMATIONS]);
        } catch (Throwable $exception) {
            return Response::html(e($this->exceptionMessage($exception)),404);
        }
    }

    public function start(Request $request,string $save,string $fixture): Response
    {
        try {$state=$this->matches->start($this->context,(int)$fixture);return $request->expectsJson()?$this->json($this->payload($state)):$this->redirect($this->saveUrl('matches/'.$fixture));}
        catch(Throwable $e){return $this->error($request,$e);}
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
        foreach (['home', 'away'] as $side) {
            $sideState = $state['sides'][$side];
            $lineup = [];
            foreach ($sideState['tactic']['lineup'] as $entry) {
                $playerId = (int)$entry['player_id'];
                $player = $sideState['lineup'][$playerId] ?? [];
                $lineup[] = [
                    'id' => $playerId, 'name' => (string)($player['name'] ?? 'Joueur'),
                    'position' => (string)$entry['position'], 'cards' => (int)($player['cards'] ?? 0),
                    'injured' => (bool)($player['injured'] ?? false),
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
            $sides[$side] = [
                'lineup' => $lineup, 'substitutes' => $substitutes,
                'substitutions' => (int)$sideState['substitutions'],
                'tactic' => array_intersect_key($sideState['tactic'], array_flip([
                    'formation', 'mentality', 'playing_style', 'pressing', 'defensive_line', 'tempo', 'width',
                ])),
            ];
        }
        return ['minute'=>$state['minute'],'status'=>$state['status'],'home_score'=>$state['home_score'],'away_score'=>$state['away_score'],'stats'=>$state['stats'],'events'=>$state['new_events'],'sides'=>$sides];
    }
    private function error(Request $request,Throwable $exception): Response
    {
        return $request->expectsJson()||in_array($request->method(),['POST','PUT','PATCH'],true)?$this->json(['message'=>$this->exceptionMessage($exception)],422):Response::html(e($this->exceptionMessage($exception)),422);
    }
}

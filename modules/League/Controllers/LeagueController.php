<?php

declare(strict_types=1);

namespace Modules\League\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Services\GameContext;
use App\Services\LeagueService;

final class LeagueController extends GameController
{
    public function __construct(Application $app,private readonly GameContext $context,private readonly Database $database,private readonly LeagueService $league)
    { parent::__construct($app); }

    public function calendar(Request $request): Response
    {
        $leagueId=(int)$request->query('league',(int)$this->context->club()['league_id']);
        $matchday=(int)$request->query('matchday',0);
        $bindings=['save_id'=>$this->context->saveId(),'season_id'=>(int)$this->context->save()['current_season_id'],'league_id'=>$leagueId];
        $where='f.save_id=:save_id AND f.season_id=:season_id AND f.league_id=:league_id';
        if($matchday>0){$where.=' AND f.matchday=:matchday';$bindings['matchday']=$matchday;}
        $fixtures=$this->database->select('SELECT f.*,h.name AS home_name,h.short_name AS home_short,h.slug AS home_slug,a.name AS away_name,a.short_name AS away_short,a.slug AS away_slug FROM fixtures f JOIN clubs h ON h.id=f.home_club_id JOIN clubs a ON a.id=f.away_club_id WHERE '.$where.' ORDER BY f.matchday,f.scheduled_at,f.id',$bindings);
        return $this->gameView('league::calendar',array_merge($this->leagueData($leagueId),['title'=>'Calendrier & résultats','active'=>'calendar','fixtures'=>$fixtures,'selectedMatchday'=>$matchday,'save'=>$this->context->save()]));
    }

    public function standings(Request $request): Response
    {
        $leagueId=(int)$request->query('league',(int)$this->context->club()['league_id']);
        $table=$this->league->table($this->context->saveId(),(int)$this->context->save()['current_season_id'],$leagueId);
        return $this->gameView('league::standings',array_merge($this->leagueData($leagueId),['title'=>'Classement','active'=>'standings','table'=>$table]));
    }

    private function leagueData(int $leagueId): array
    {
        $leagues=$this->database->select('SELECT * FROM leagues WHERE save_id=:save_id ORDER BY level',['save_id'=>$this->context->saveId()]);
        $league=null;foreach($leagues as $row){if((int)$row['id']===$leagueId)$league=$row;}
        if($league===null)throw new \RuntimeException('Division invalide.');
        return ['leagues'=>$leagues,'league'=>$league];
    }
}

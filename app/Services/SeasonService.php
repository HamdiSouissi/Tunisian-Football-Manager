<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventDispatcherInterface;
use App\Core\Config;
use App\Database\Database;
use App\Models\PlayerRepository;
use DateTimeImmutable;

final class SeasonService
{
    public function __construct(
        private readonly Database $database,
        private readonly PlayerFactory $playersFactory,
        private readonly PlayerRepository $players,
        private readonly FixtureGenerator $fixtureGenerator,
        private readonly TacticService $tactics,
        private readonly MessageService $messages,
        private readonly EventDispatcherInterface $events,
        private readonly Config $config
    ) {
    }

    public function finishIfNeeded(GameContext $context,string $date): bool
    {
        $season=$this->database->selectOne('SELECT * FROM seasons WHERE save_id=:save_id AND id=:season_id',['save_id'=>$context->saveId(),'season_id'=>(int)$context->save()['current_season_id']]);
        if(!$season||$season['status']==='finished'||$date<=$season['ends_on'])return false;

        $this->database->transaction(function()use($context,$season,$date):void{
            $saveId=$context->saveId();$seasonId=(int)$season['id'];
            $this->database->execute('UPDATE seasons SET status=\'finished\' WHERE id=:id AND save_id=:save_id',['id'=>$seasonId,'save_id'=>$saveId]);
            $this->evaluateObjectives($context,$seasonId);
            $this->promoteAndRelegate($saveId,$seasonId);
            $newStart=new DateTimeImmutable((string)$season['starts_on']);$newStart=$newStart->modify('+1 year');
            $this->expireContractsAndRetire($saveId,$newStart);
            $newSeasonId=(int)$this->database->insert('INSERT INTO seasons (save_id,name,starts_on,ends_on,status) VALUES (:save_id,:name,:starts_on,:ends_on,\'active\')',['save_id'=>$saveId,'name'=>$newStart->format('Y').'-'.$newStart->modify('+1 year')->format('Y'),'starts_on'=>$newStart->format('Y-m-d'),'ends_on'=>$newStart->modify('+10 months')->format('Y-m-t')]);
            $this->database->execute('UPDATE saves SET current_season_id=:season_id,current_date=:date WHERE id=:save_id',['season_id'=>$newSeasonId,'date'=>$newStart->format('Y-m-d'),'save_id'=>$saveId]);
            $this->seedYouth($saveId,$newStart);
            $this->resetClubsAndCompetitions($context,$newSeasonId,$newStart);
            $this->messages->send($saveId,$context->userId(),$context->clubId(),'Nouvelle saison '.$newStart->format('Y'),'Le centre de formation a promu de nouveaux jeunes. Les promotions, relégations et contrats ont été actualisés.','board','/saves/'.$saveId);
            $this->events->dispatch('season.finished',['save_id'=>$saveId,'season_id'=>$seasonId]);
            $this->events->dispatch('season.started',['save_id'=>$saveId,'season_id'=>$newSeasonId]);
        });
        $context->refresh();
        return true;
    }

    private function evaluateObjectives(GameContext $context,int $seasonId): void
    {
        $objectives=$this->database->select('SELECT o.*,s.position,l.level FROM board_objectives o JOIN standings s ON s.club_id=o.club_id AND s.season_id=o.season_id JOIN leagues l ON l.id=s.league_id WHERE o.save_id=:save_id AND o.season_id=:season_id',['save_id'=>$context->saveId(),'season_id'=>$seasonId]);
        foreach($objectives as $objective){
            $position=(int)$objective['position'];$achieved=match($objective['objective']){'title'=>$position===1,'top_three'=>$position<=3,'top_half'=>$position<=8,'promotion'=>$position<=2,default=>$position<=14};
            $this->database->execute('UPDATE board_objectives SET status=:status WHERE id=:id',['status'=>$achieved?'achieved':'failed','id'=>(int)$objective['id']]);
            if((int)$objective['club_id']===$context->clubId()){
                $this->database->execute('UPDATE clubs SET board_confidence=GREATEST(5,LEAST(100,board_confidence+:delta)) WHERE save_id=:save_id AND id=:club_id',['delta'=>$achieved?15:-12,'save_id'=>$context->saveId(),'club_id'=>$context->clubId()]);
                $this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Bilan de la direction','Objectif de saison : '.($achieved?'atteint. La direction salue votre travail.':'manqué. La direction attend une réaction.'),'board');
            }
        }
    }

    private function promoteAndRelegate(int $saveId,int $seasonId): void
    {
        $leagues=$this->database->select('SELECT * FROM leagues WHERE save_id=:save_id ORDER BY level',['save_id'=>$saveId]);
        if(count($leagues)<2)return;
        $one=(int)$leagues[0]['id'];$two=(int)$leagues[1]['id'];
        $relegated=$this->database->select('SELECT club_id FROM standings WHERE save_id=:save_id AND season_id=:season_id AND league_id=:league_id ORDER BY position DESC LIMIT 2',['save_id'=>$saveId,'season_id'=>$seasonId,'league_id'=>$one]);
        $promoted=$this->database->select('SELECT club_id FROM standings WHERE save_id=:save_id AND season_id=:season_id AND league_id=:league_id ORDER BY position LIMIT 2',['save_id'=>$saveId,'season_id'=>$seasonId,'league_id'=>$two]);
        foreach($relegated as $row)$this->database->execute('UPDATE clubs SET league_id=:league_id WHERE save_id=:save_id AND id=:club_id',['league_id'=>$two,'save_id'=>$saveId,'club_id'=>(int)$row['club_id']]);
        foreach($promoted as $row)$this->database->execute('UPDATE clubs SET league_id=:league_id WHERE save_id=:save_id AND id=:club_id',['league_id'=>$one,'save_id'=>$saveId,'club_id'=>(int)$row['club_id']]);
    }

    private function expireContractsAndRetire(int $saveId,DateTimeImmutable $newStart): void
    {
        $this->database->execute('UPDATE players p JOIN contracts c ON c.player_id=p.id AND c.status=\'active\' SET p.club_id=NULL,c.status=\'expired\' WHERE p.save_id=:save_id AND c.ends_on<=:date',['save_id'=>$saveId,'date'=>$newStart->format('Y-m-d')]);
        $retired=$this->database->select('SELECT id FROM players WHERE save_id=:save_id AND is_retired=0 AND TIMESTAMPDIFF(YEAR,birth_date,:date)>=37',['save_id'=>$saveId,'date'=>$newStart->format('Y-m-d')]);
        foreach($retired as $player){$this->database->execute('UPDATE players SET is_retired=1,club_id=NULL WHERE save_id=:save_id AND id=:player_id',['save_id'=>$saveId,'player_id'=>(int)$player['id']]);$this->database->execute('UPDATE contracts SET status=\'expired\' WHERE save_id=:save_id AND player_id=:player_id AND status=\'active\'',['save_id'=>$saveId,'player_id'=>(int)$player['id']]);}
    }

    private function seedYouth(int $saveId,DateTimeImmutable $start): void
    {
        $clubs=$this->database->select('SELECT id,reputation FROM clubs WHERE save_id=:save_id',['save_id'=>$saveId]);
        foreach($clubs as $club){
            for($i=0;$i<2;$i++){
                $positions=['GK','DC','DL','DR','MDC','MC','ML','MR','AMC','ST'];$position=$positions[array_rand($positions)];
                $overall=random_int(42,52)+(int)round((int)$club['reputation']/25);$data=$this->playersFactory->make($position,random_int(16,18),$overall,random_int(min(85,$overall+12),99),'Tunisia',$start);
                $this->insertYouth($saveId,(int)$club['id'],$data,$start);
            }
        }
    }

    private function insertYouth(int $saveId,int $clubId,array $data,DateTimeImmutable $start): void
    {
        $p=$data['player'];
        $playerId=(int)$this->database->insert('INSERT INTO players (save_id,club_id,first_name,last_name,birth_date,nationality,primary_position,secondary_positions,preferred_foot,height_cm,weight_kg,market_value,overall_rating,potential,form,morale,fatigue) VALUES (:save_id,:club_id,:first_name,:last_name,:birth_date,:nationality,:position,:secondary,:foot,:height,:weight,:value,:overall,:potential,:form,:morale,0)',['save_id'=>$saveId,'club_id'=>$clubId,'first_name'=>$p['first_name'],'last_name'=>$p['last_name'],'birth_date'=>$p['birth_date'],'nationality'=>$p['nationality'],'position'=>$p['primary_position'],'secondary'=>$p['secondary_positions'],'foot'=>$p['preferred_foot'],'height'=>$p['height_cm'],'weight'=>$p['weight_kg'],'value'=>$p['market_value'],'overall'=>$p['overall_rating'],'potential'=>$p['potential'],'form'=>$p['form'],'morale'=>$p['morale']]);
        $params=['save_id'=>$saveId,'player_id'=>$playerId];foreach(PlayerFactory::ATTRIBUTES as $attribute)$params[$attribute]=$data['attributes'][$attribute];
        $this->database->insert('INSERT INTO player_attributes (save_id,player_id,'.implode(',',PlayerFactory::ATTRIBUTES).') VALUES (:save_id,:player_id,:'.implode(',:',PlayerFactory::ATTRIBUTES).')',$params);
        $this->database->insert('INSERT INTO contracts (save_id,player_id,club_id,starts_on,ends_on,salary,squad_role) VALUES (:save_id,:player_id,:club_id,:starts_on,:ends_on,:salary,\'prospect\')',['save_id'=>$saveId,'player_id'=>$playerId,'club_id'=>$clubId,'starts_on'=>$start->format('Y-m-d'),'ends_on'=>$start->modify('+3 years')->format('Y-m-d'),'salary'=>$data['salary']]);
    }

    private function resetClubsAndCompetitions(GameContext $context,int $seasonId,DateTimeImmutable $start): void
    {
        $saveId=$context->saveId();$clubs=$this->database->select('SELECT c.*,l.level FROM clubs c JOIN leagues l ON l.id=c.league_id WHERE c.save_id=:save_id ORDER BY c.league_id,c.id',['save_id'=>$saveId]);$byLeague=[];
        $firstMatch=$start->modify('first day of August');if((int)$firstMatch->format('N')!==6)$firstMatch=$firstMatch->modify('next saturday');
        foreach($clubs as $club){
            $clubId=(int)$club['id'];$byLeague[(int)$club['league_id']][]=$clubId;
            $multiplier=$clubId===$context->clubId()?(float)$this->config->get('game.difficulties.'.$context->save()['difficulty'].'.budget_multiplier',1):1;
            $this->database->execute('UPDATE clubs SET transfer_budget=:budget,wage_budget=:wage_budget,balance=balance+:grant WHERE save_id=:save_id AND id=:club_id',['budget'=>(int)$club['reputation']**2*190*$multiplier,'wage_budget'=>(int)$club['reputation']**2*5.5*$multiplier,'grant'=>(int)$club['reputation']**2*80,'save_id'=>$saveId,'club_id'=>$clubId]);
            $this->database->insert('INSERT INTO standings (save_id,season_id,league_id,club_id,position) VALUES (:save_id,:season_id,:league_id,:club_id,:position)',['save_id'=>$saveId,'season_id'=>$seasonId,'league_id'=>(int)$club['league_id'],'club_id'=>$clubId,'position'=>count($byLeague[(int)$club['league_id']])]);
            $objective=(int)$club['level']===2?((int)$club['reputation']>=54?'promotion':'top_half'):((int)$club['reputation']>=86?'title':((int)$club['reputation']>=75?'top_three':((int)$club['reputation']>=64?'top_half':'avoid_relegation')));
            $this->database->insert('INSERT INTO board_objectives (save_id,season_id,club_id,objective) VALUES (:save_id,:season_id,:club_id,:objective)',['save_id'=>$saveId,'season_id'=>$seasonId,'club_id'=>$clubId,'objective'=>$objective]);
            $this->database->insert('INSERT INTO training_plans (save_id,club_id,week_start,focus,intensity) VALUES (:save_id,:club_id,:week_start,\'tactical\',\'medium\')',['save_id'=>$saveId,'club_id'=>$clubId,'week_start'=>$start->modify('monday this week')->format('Y-m-d')]);
            $squad=$this->players->forClub($saveId,$clubId);if(count($squad)>=11)$this->tactics->createDefault($saveId,$clubId,$squad);
        }
        foreach($byLeague as $leagueId=>$ids){
            foreach($this->fixtureGenerator->generate($ids,$firstMatch) as $fixture){
                $this->database->insert('INSERT INTO fixtures (save_id,season_id,league_id,round_number,leg,matchday,scheduled_at,home_club_id,away_club_id) VALUES (:save_id,:season_id,:league_id,:round_number,:leg,:matchday,:scheduled_at,:home,:away)',['save_id'=>$saveId,'season_id'=>$seasonId,'league_id'=>$leagueId,'round_number'=>$fixture['matchday'],'leg'=>$fixture['leg'],'matchday'=>$fixture['matchday'],'scheduled_at'=>$fixture['date']->format('Y-m-d H:i:s'),'home'=>$fixture['home'],'away'=>$fixture['away']]);
            }
        }
    }
}

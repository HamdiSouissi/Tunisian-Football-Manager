<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use App\Events\Event;

final class FinanceService
{
    public function __construct(private readonly Database $database,private readonly Config $config,private readonly MessageService $messages)
    {
    }

    public function record(int $saveId,int $clubId,?int $seasonId,string $date,string $direction,string $category,float $amount,string $description,?string $referenceType=null,?int $referenceId=null): void
    {
        if($amount<=0||!in_array($direction,['income','expense'],true))return;
        if($referenceType&&$referenceId){
            $exists=$this->database->selectOne('SELECT id FROM finances WHERE save_id=:save_id AND club_id=:club_id AND category=:category AND reference_type=:reference_type AND reference_id=:reference_id',['save_id'=>$saveId,'club_id'=>$clubId,'category'=>$category,'reference_type'=>$referenceType,'reference_id'=>$referenceId]);
            if($exists)return;
        }
        $this->database->insert('INSERT INTO finances (save_id,club_id,season_id,occurred_on,direction,category,description,amount,reference_type,reference_id) VALUES (:save_id,:club_id,:season_id,:date,:direction,:category,:description,:amount,:reference_type,:reference_id)',['save_id'=>$saveId,'club_id'=>$clubId,'season_id'=>$seasonId,'date'=>$date,'direction'=>$direction,'category'=>$category,'description'=>$description,'amount'=>round($amount,2),'reference_type'=>$referenceType,'reference_id'=>$referenceId]);
        $this->database->execute('UPDATE clubs SET balance=balance+:amount WHERE save_id=:save_id AND id=:club_id',['amount'=>$direction==='income'?$amount:-$amount,'save_id'=>$saveId,'club_id'=>$clubId]);
    }

    public function adjustTransferBudget(int $saveId,int $clubId,float $amount): void
    {
        $this->database->execute('UPDATE clubs SET transfer_budget=GREATEST(0,transfer_budget+:amount) WHERE save_id=:save_id AND id=:club_id',['amount'=>$amount,'save_id'=>$saveId,'club_id'=>$clubId]);
    }

    public function onMatchFinished(Event $event): void
    {
        $fixture=$event->get('fixture');if(!is_array($fixture))return;$saveId=(int)$event->get('save_id');
        $home=$this->database->selectOne('SELECT * FROM clubs WHERE save_id=:save_id AND id=:club_id',['save_id'=>$saveId,'club_id'=>(int)$fixture['home_club_id']]);
        if($home){
            $attendance=(int)round(min((int)$home['stadium_capacity'],(int)$home['stadium_capacity']*(.42+(int)$home['reputation']/220+(int)$home['board_confidence']/400)));
            $income=$attendance*(float)$this->config->get('game.finance.ticket_price',12);
            $this->database->execute('UPDATE fixtures SET attendance=:attendance WHERE save_id=:save_id AND id=:fixture_id',['attendance'=>$attendance,'save_id'=>$saveId,'fixture_id'=>(int)$fixture['id']]);
            $this->record($saveId,(int)$home['id'],(int)$fixture['season_id'],date('Y-m-d',strtotime((string)$fixture['scheduled_at'])),'income','ticketing',$income,'Billetterie · '.$attendance.' spectateurs','fixture',(int)$fixture['id']);
        }
        $save=$this->database->selectOne('SELECT * FROM saves WHERE id=:save_id',['save_id'=>$saveId]);
        if(!$save||!in_array((int)$save['club_id'],[(int)$fixture['home_club_id'],(int)$fixture['away_club_id']],true))return;
        $isHome=(int)$save['club_id']===(int)$fixture['home_club_id'];$own=$isHome?(int)$fixture['home_score']:(int)$fixture['away_score'];$other=$isHome?(int)$fixture['away_score']:(int)$fixture['home_score'];
        $base=$own>$other?(float)$this->config->get('game.finance.board_confidence_win',2):($own===$other?(float)$this->config->get('game.finance.board_confidence_draw',0):(float)$this->config->get('game.finance.board_confidence_loss',-3));
        if($base<0)$base*=(float)$this->config->get('game.difficulties.'.$save['difficulty'].'.confidence_loss',1);
        $this->database->execute('UPDATE clubs SET board_confidence=GREATEST(0,LEAST(100,board_confidence+:delta)) WHERE save_id=:save_id AND id=:club_id',['delta'=>$base,'save_id'=>$saveId,'club_id'=>(int)$save['club_id']]);
        $club=$this->database->selectOne('SELECT board_confidence FROM clubs WHERE save_id=:save_id AND id=:club_id',['save_id'=>$saveId,'club_id'=>(int)$save['club_id']]);
        if((int)($club['board_confidence']??50)<=0&&(string)$save['status']==='active'){
            $this->database->execute('UPDATE saves SET status=\'completed\' WHERE id=:save_id',['save_id'=>$saveId]);
            $this->messages->send($saveId,(int)$save['user_id'],(int)$save['club_id'],'Licenciement','La confiance de la direction est tombée à zéro. Votre carrière dans ce club est terminée.','board','/saves');
        }
    }

    public function onTransferCompleted(Event $event): void
    {
        $saveId=(int)$event->get('save_id');$transferId=(int)$event->get('transfer_id');$fee=(float)$event->get('fee');$date=(string)$event->get('date');$seasonId=$event->get('season_id')!==null?(int)$event->get('season_id'):null;
        $to=$event->get('to_club_id');$from=$event->get('from_club_id');$bonus=(float)$event->get('signing_bonus');
        if($fee<=0&&$bonus<=0)return;
        if($to){
            $this->record($saveId,(int)$to,$seasonId,$date,'expense','transfer_purchase',$fee,'Achat de joueur','transfer',$transferId);
            $this->record($saveId,(int)$to,$seasonId,$date,'expense','signing_bonus',$bonus,'Prime à la signature','transfer',$transferId);
            $this->adjustTransferBudget($saveId,(int)$to,-($fee+$bonus));
        }
        if($from){$this->record($saveId,(int)$from,$seasonId,$date,'income','transfer_sale',$fee,'Vente de joueur','transfer',$transferId);$this->adjustTransferBudget($saveId,(int)$from,$fee*.7);}
    }

    public function daily(int $saveId,string $date): void
    {
        $weekday=(int)date('N',strtotime($date));
        if($weekday===1){
            $clubs=$this->database->select('SELECT c.*,s.id AS season_id,s.current_season_id FROM clubs c JOIN saves s ON s.id=c.save_id WHERE c.save_id=:save_id',['save_id'=>$saveId]);
            foreach($clubs as $club){
                $wages=$this->database->selectOne('SELECT COALESCE(SUM(salary),0) AS total FROM contracts WHERE save_id=:save_id AND club_id=:club_id AND status=\'active\'',['save_id'=>$saveId,'club_id'=>(int)$club['id']]);
                $staff=$this->database->selectOne('SELECT COALESCE(SUM(wage),0) AS total FROM staff WHERE save_id=:save_id AND club_id=:club_id',['save_id'=>$saveId,'club_id'=>(int)$club['id']]);
                $this->record($saveId,(int)$club['id'],(int)$club['current_season_id'],$date,'expense','wages',(float)$wages['total']+(float)$staff['total'],'Salaires hebdomadaires joueurs et staff');
                $this->record($saveId,(int)$club['id'],(int)$club['current_season_id'],$date,'income','sponsors',(int)$club['reputation']*(float)$this->config->get('game.finance.weekly_sponsor_reputation_factor',85),'Partenaires commerciaux hebdomadaires');
            }
        }
        if((int)date('j',strtotime($date))===1&&in_array((int)date('n',strtotime($date)),[8,1],true)){
            $clubs=$this->database->select('SELECT c.*,l.level,s.current_season_id FROM clubs c JOIN leagues l ON l.id=c.league_id JOIN saves s ON s.id=c.save_id WHERE c.save_id=:save_id',['save_id'=>$saveId]);
            foreach($clubs as $club){$amount=(float)$this->config->get('game.finance.tv_rights_league_'.((int)$club['level']===1?'one':'two'),50000);$this->record($saveId,(int)$club['id'],(int)$club['current_season_id'],$date,'income','tv_rights',$amount,'Droits TV semestriels','tv',((int)$club['current_season_id']*100)+(int)date('n',strtotime($date)));}
        }
    }

    public function dashboard(GameContext $context): array
    {
        $saveId=$context->saveId();$clubId=$context->clubId();$seasonId=(int)$context->save()['current_season_id'];
        $ledger=$this->database->select('SELECT * FROM finances WHERE save_id=:save_id AND club_id=:club_id ORDER BY occurred_on DESC,id DESC LIMIT 150',['save_id'=>$saveId,'club_id'=>$clubId]);
        $totals=$this->database->selectOne('SELECT COALESCE(SUM(IF(direction=\'income\',amount,0)),0) AS income,COALESCE(SUM(IF(direction=\'expense\',amount,0)),0) AS expense FROM finances WHERE save_id=:save_id AND club_id=:club_id AND season_id=:season_id',['save_id'=>$saveId,'club_id'=>$clubId,'season_id'=>$seasonId]);
        $monthly=$this->database->select('SELECT DATE_FORMAT(occurred_on,\'%Y-%m\') AS month,SUM(IF(direction=\'income\',amount,-amount)) AS net FROM finances WHERE save_id=:save_id AND club_id=:club_id GROUP BY month ORDER BY month DESC LIMIT 8',['save_id'=>$saveId,'club_id'=>$clubId]);
        $wages=$this->database->selectOne('SELECT COALESCE(SUM(salary),0) AS total FROM contracts WHERE save_id=:save_id AND club_id=:club_id AND status=\'active\'',['save_id'=>$saveId,'club_id'=>$clubId]);
        return ['ledger'=>$ledger,'totals'=>$totals,'monthly'=>array_reverse($monthly),'weeklyWages'=>(float)$wages['total']];
    }
}

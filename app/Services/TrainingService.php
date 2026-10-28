<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventDispatcherInterface;
use App\Core\Config;
use App\Database\Database;
use App\Models\PlayerRepository;
use App\Security\ValidationException;
use DateTimeImmutable;
use RuntimeException;

final class TrainingService
{
    public function __construct(
        private readonly Database $database,
        private readonly PlayerRepository $players,
        private readonly Config $config,
        private readonly EventDispatcherInterface $events
    ) {
    }

    public function currentPlan(GameContext $context): array
    {
        $week=$this->weekStart((string)$context->save()['current_date']);
        $plan=$this->database->selectOne('SELECT * FROM training_plans WHERE save_id=:save_id AND club_id=:club_id AND week_start=:week_start',['save_id'=>$context->saveId(),'club_id'=>$context->clubId(),'week_start'=>$week]);
        if($plan===null){
            $id=$this->savePlan($context,'tactical','medium',[]);
            $plan=$this->database->selectOne('SELECT * FROM training_plans WHERE id=:id',['id'=>$id]);
        }
        $plan['individual']=$this->database->select('SELECT * FROM training_plan_players WHERE save_id=:save_id AND training_plan_id=:plan_id',['save_id'=>$context->saveId(),'plan_id'=>(int)$plan['id']]);
        $plan['report']=$this->database->selectOne('SELECT * FROM training_reports WHERE save_id=:save_id AND training_plan_id=:plan_id',['save_id'=>$context->saveId(),'plan_id'=>(int)$plan['id']]);
        return $plan;
    }

    public function savePlan(GameContext $context,string $focus,string $intensity,array $individual): int
    {
        $focuses=array_keys((array)$this->config->get('game.training.focus_attributes',[]));
        if(!in_array($focus,$focuses,true)||!in_array($intensity,['low','medium','high'],true))throw new ValidationException(['training'=>'Programme ou intensité invalide.']);
        $week=$this->weekStart((string)$context->save()['current_date']);
        $existing=$this->database->selectOne('SELECT id FROM training_plans WHERE save_id=:save_id AND club_id=:club_id AND week_start=:week_start',['save_id'=>$context->saveId(),'club_id'=>$context->clubId(),'week_start'=>$week]);
        if($existing){
            $planId=(int)$existing['id'];
            $this->database->execute('UPDATE training_plans SET focus=:focus,intensity=:intensity WHERE id=:id AND save_id=:save_id',['focus'=>$focus,'intensity'=>$intensity,'id'=>$planId,'save_id'=>$context->saveId()]);
            $this->database->execute('DELETE FROM training_plan_players WHERE save_id=:save_id AND training_plan_id=:plan_id',['save_id'=>$context->saveId(),'plan_id'=>$planId]);
        }else{
            $planId=(int)$this->database->insert('INSERT INTO training_plans (save_id,club_id,week_start,focus,intensity) VALUES (:save_id,:club_id,:week_start,:focus,:intensity)',['save_id'=>$context->saveId(),'club_id'=>$context->clubId(),'week_start'=>$week,'focus'=>$focus,'intensity'=>$intensity]);
        }
        $validAttributes=PlayerFactory::ATTRIBUTES;
        $squad=$this->players->forClub($context->saveId(),$context->clubId());$allowed=array_fill_keys(array_map(static fn($p)=>(int)$p['id'],$squad),true);
        foreach($individual as $playerId=>$attribute){
            $playerId=(int)$playerId;
            if(isset($allowed[$playerId])&&in_array($attribute,$validAttributes,true)){
                $this->database->insert('INSERT INTO training_plan_players (save_id,training_plan_id,player_id,target_attribute,intensity) VALUES (:save_id,:plan_id,:player_id,:attribute,:intensity)',['save_id'=>$context->saveId(),'plan_id'=>$planId,'player_id'=>$playerId,'attribute'=>$attribute,'intensity'=>$intensity]);
            }
        }
        return $planId;
    }

    public function latestReport(GameContext $context): ?array
    {
        $report=$this->database->selectOne('SELECT * FROM training_reports WHERE save_id=:save_id AND club_id=:club_id ORDER BY week_start DESC,id DESC LIMIT 1',['save_id'=>$context->saveId(),'club_id'=>$context->clubId()]);
        if($report===null)return null;
        $report['entries']=$this->database->select('SELECT e.*,p.first_name,p.last_name,p.primary_position FROM training_report_entries e JOIN players p ON p.id=e.player_id WHERE e.save_id=:save_id AND e.training_report_id=:report_id ORDER BY e.new_value-e.previous_value DESC,p.last_name',['save_id'=>$context->saveId(),'report_id'=>(int)$report['id']]);
        return $report;
    }

    public function applyWeek(int $saveId,string $date,?GameContext $context=null): int
    {
        $week=$this->weekStart($date);
        $clubs=$this->database->select('SELECT id FROM clubs WHERE save_id=:save_id ORDER BY id',['save_id'=>$saveId]);$applied=0;
        foreach($clubs as $club){
            $clubId=(int)$club['id'];
            $plan=$this->database->selectOne('SELECT * FROM training_plans WHERE save_id=:save_id AND club_id=:club_id AND week_start=:week_start',['save_id'=>$saveId,'club_id'=>$clubId,'week_start'=>$week]);
            if($plan===null){
                $planId=(int)$this->database->insert('INSERT INTO training_plans (save_id,club_id,week_start,focus,intensity) VALUES (:save_id,:club_id,:week_start,\'tactical\',\'medium\')',['save_id'=>$saveId,'club_id'=>$clubId,'week_start'=>$week]);
                $plan=$this->database->selectOne('SELECT * FROM training_plans WHERE id=:id',['id'=>$planId]);
            }
            $already=$this->database->selectOne('SELECT id FROM training_reports WHERE save_id=:save_id AND training_plan_id=:plan_id',['save_id'=>$saveId,'plan_id'=>(int)$plan['id']]);
            if($already===null){$this->applyPlan($saveId,$plan,$date,$context);$applied++;}
        }
        return $applied;
    }

    private function applyPlan(int $saveId,array $plan,string $date,?GameContext $context): void
    {
        $clubId=(int)$plan['club_id'];$players=$this->players->forClub($saveId,$clubId);
        $staff=$this->database->selectOne('SELECT AVG(quality) AS quality FROM staff WHERE save_id=:save_id AND club_id=:club_id',['save_id'=>$saveId,'club_id'=>$clubId]);
        $staffQuality=(float)($staff['quality']??50);$intensity=(string)$plan['intensity'];
        $settings=(array)$this->config->get('game.training.intensity.'.$intensity,[]);
        $focuses=(array)$this->config->get('game.training.focus_attributes',[]);$focusAttributes=$focuses[$plan['focus']]??[];
        $individualRows=$this->database->select('SELECT * FROM training_plan_players WHERE save_id=:save_id AND training_plan_id=:plan_id',['save_id'=>$saveId,'plan_id'=>(int)$plan['id']]);
        $individual=array_column($individualRows,'target_attribute','player_id');
        $reportId=(int)$this->database->insert('INSERT INTO training_reports (save_id,training_plan_id,club_id,week_start,summary) VALUES (:save_id,:plan_id,:club_id,:week_start,\'\')',['save_id'=>$saveId,'plan_id'=>(int)$plan['id'],'club_id'=>$clubId,'week_start'=>$plan['week_start']]);
        $progressions=0;$injuries=0;
        foreach($players as $player){
            $playerId=(int)$player['id'];$attributes=[];foreach(PlayerFactory::ATTRIBUTES as $attribute)$attributes[$attribute]=(int)$player[$attribute];
            $previousFatigue=(int)$player['fatigue'];$fatigueDelta=$plan['focus']==='recovery'?-18:(int)$settings['fatigue'];
            $moraleDelta=$plan['focus']==='recovery'?2:0;$changedAttribute=null;$previous=null;$new=null;$injury=false;
            $age=player_age((string)$player['birth_date'],$date);
            $canTrain=empty($player['injury_type']);
            if($canTrain&&$focusAttributes!==[]){
                $target=$individual[$playerId]??$focusAttributes[array_rand($focusAttributes)];
                $ageFactor=$age<=21?1.55:($age<=25?1.12:($age<=29?.70:.35));
                $potentialRoom=max(0,(int)$player['potential']-(int)$attributes[$target]);
                $chance=.23*(float)$settings['development']*$ageFactor*(.65+$staffQuality/180)*($potentialRoom>0?1:.12);
                if(isset($individual[$playerId]))$chance*=1.55;
                if($this->random()<$chance&&$attributes[$target]<99){
                    $changedAttribute=$target;$previous=$attributes[$target];$attributes[$target]++;$new=$attributes[$target];$progressions++;
                }
                if($age>=31&&$this->random()<.08){$physical=['pace','stamina','strength'];$decline=$physical[array_rand($physical)];if($attributes[$decline]>20){$changedAttribute=$decline;$previous=$attributes[$decline];$attributes[$decline]--;$new=$attributes[$decline];}}
            }
            $injuryProbability=(float)$settings['injury']*(1+(int)$player['fatigue']/100)*(1.25-$staffQuality/250);
            if($canTrain&&$plan['focus']!=='recovery'&&$this->random()<$injuryProbability){$injury=true;$injuries++;}
            $overall=PlayerFactory::overall((string)$player['primary_position'],$attributes);
            $newFatigue=max(0,min(100,$previousFatigue+$fatigueDelta));
            $this->database->execute('UPDATE players SET overall_rating=:overall,fatigue=:fatigue,morale=GREATEST(10,LEAST(100,morale+:morale)),market_value=GREATEST(10000,market_value*(1+:growth/250)) WHERE save_id=:save_id AND id=:player_id',['overall'=>$overall,'fatigue'=>$newFatigue,'morale'=>$moraleDelta,'growth'=>($new!==null&&$previous!==null?$new-$previous:0),'save_id'=>$saveId,'player_id'=>$playerId]);
            if($changedAttribute){
                $this->database->execute("UPDATE player_attributes SET $changedAttribute=:value WHERE save_id=:save_id AND player_id=:player_id",['value'=>$new,'save_id'=>$saveId,'player_id'=>$playerId]);
            }
            if($injury){$days=random_int(3,28);$until=(new DateTimeImmutable($date))->modify('+'.$days.' days')->format('Y-m-d');$this->database->execute('UPDATE players SET injury_type=\'Blessure à l’entraînement\',injury_until=:injury_until WHERE save_id=:save_id AND id=:player_id',['injury_until'=>$until,'save_id'=>$saveId,'player_id'=>$playerId]);}
            $this->database->insert('INSERT INTO training_report_entries (save_id,training_report_id,player_id,attribute,previous_value,new_value,fatigue_delta,morale_delta,injury_occurred,notes) VALUES (:save_id,:report_id,:player_id,:attribute,:previous,:new,:fatigue,:morale,:injury,:notes)',['save_id'=>$saveId,'report_id'=>$reportId,'player_id'=>$playerId,'attribute'=>$changedAttribute,'previous'=>$previous,'new'=>$new,'fatigue'=>$newFatigue-$previousFatigue,'morale'=>$moraleDelta,'injury'=>$injury?1:0,'notes'=>$injury?'Indisponible après une blessure':null]);
        }
        $this->database->execute('UPDATE training_reports SET summary=:summary WHERE id=:id',['summary'=>$progressions.' progression(s), '.$injuries.' blessure(s).','id'=>$reportId]);
        $this->database->execute('UPDATE training_plans SET applied_at=NOW() WHERE id=:id AND save_id=:save_id',['id'=>(int)$plan['id'],'save_id'=>$saveId]);
        $this->events->dispatch('training.applied',['save_id'=>$saveId,'club_id'=>$clubId,'training_plan_id'=>(int)$plan['id'],'training_report_id'=>$reportId]);
        if($context!==null&&$context->clubId()===$clubId){
            $this->database->insert('INSERT INTO messages (save_id,user_id,club_id,subject,body,category,action_url) VALUES (:save_id,:user_id,:club_id,:subject,:body,\'training\',:action_url)',['save_id'=>$saveId,'user_id'=>$context->userId(),'club_id'=>$clubId,'subject'=>'Rapport d’entraînement','body'=>$progressions.' progression(s) et '.$injuries.' blessure(s) cette semaine.','action_url'=>'/saves/'.$saveId.'/training']);
        }
    }

    private function weekStart(string $date): string { return (new DateTimeImmutable($date))->modify('monday this week')->format('Y-m-d'); }
    private function random(): float { return random_int(0,1000000)/1000000; }
}

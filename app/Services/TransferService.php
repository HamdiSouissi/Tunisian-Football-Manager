<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AIManagerInterface;
use App\Contracts\EventDispatcherInterface;
use App\Core\Config;
use App\Database\Database;
use App\Models\PlayerRepository;
use App\Security\ValidationException;
use DateTimeImmutable;
use RuntimeException;

final class TransferService
{
    public function __construct(
        private readonly Database $database,
        private readonly PlayerRepository $players,
        private readonly AIManagerInterface $ai,
        private readonly EventDispatcherInterface $events,
        private readonly MessageService $messages,
        private readonly Config $config
    ) {
    }

    public function isWindowOpen(string $date): bool
    {
        $monthDay=date('m-d',strtotime($date));
        foreach((array)$this->config->get('game.transfers.windows',[]) as $window){if($monthDay>=$window['start']&&$monthDay<=$window['end'])return true;}
        return false;
    }

    public function offers(GameContext $context): array
    {
        return $this->database->select('SELECT o.*,p.first_name,p.last_name,p.primary_position,p.overall_rating,p.market_value,fc.name AS from_name,tc.name AS to_name FROM transfer_offers o JOIN players p ON p.id=o.player_id LEFT JOIN clubs fc ON fc.id=o.from_club_id LEFT JOIN clubs tc ON tc.id=o.to_club_id WHERE o.save_id=:save_id AND (o.from_club_id=:from_club OR o.to_club_id=:to_club) ORDER BY o.created_at DESC,o.id DESC',['save_id'=>$context->saveId(),'from_club'=>$context->clubId(),'to_club'=>$context->clubId()]);
    }

    public function history(GameContext $context): array
    {
        return $this->database->select('SELECT t.*,p.first_name,p.last_name,fc.name AS from_name,tc.name AS to_name FROM transfers t JOIN players p ON p.id=t.player_id LEFT JOIN clubs fc ON fc.id=t.from_club_id LEFT JOIN clubs tc ON tc.id=t.to_club_id WHERE t.save_id=:save_id AND (t.from_club_id=:from_club OR t.to_club_id=:to_club) ORDER BY t.transfer_date DESC,t.id DESC LIMIT 150',['save_id'=>$context->saveId(),'from_club'=>$context->clubId(),'to_club'=>$context->clubId()]);
    }

    public function shortlist(GameContext $context): array
    {
        return $this->database->select('SELECT p.*,s.notes,c.name AS club_name FROM shortlisted_players s JOIN players p ON p.id=s.player_id LEFT JOIN clubs c ON c.id=p.club_id WHERE s.save_id=:save_id AND s.user_id=:user_id ORDER BY s.created_at DESC',['save_id'=>$context->saveId(),'user_id'=>$context->userId()]);
    }

    public function toggleShortlist(GameContext $context,int $playerId): bool
    {
        if($this->players->find($context->saveId(),$playerId)===null)throw new RuntimeException('Joueur introuvable.');
        $existing=$this->database->selectOne('SELECT id FROM shortlisted_players WHERE save_id=:save_id AND user_id=:user_id AND player_id=:player_id',['save_id'=>$context->saveId(),'user_id'=>$context->userId(),'player_id'=>$playerId]);
        if($existing){$this->database->execute('DELETE FROM shortlisted_players WHERE id=:id AND save_id=:save_id',['id'=>(int)$existing['id'],'save_id'=>$context->saveId()]);return false;}
        $this->database->insert('INSERT INTO shortlisted_players (save_id,user_id,player_id) VALUES (:save_id,:user_id,:player_id)',['save_id'=>$context->saveId(),'user_id'=>$context->userId(),'player_id'=>$playerId]);return true;
    }

    public function makeOffer(GameContext $context,array $input): int
    {
        $this->assertWindow($context);
        $player=$this->players->find($context->saveId(),(int)($input['player_id']??0));
        if($player===null)throw new ValidationException(['player'=>'Joueur introuvable.']);
        $direction=(string)($input['direction']??'buy');$from=null;$to=null;
        if($direction==='buy'){
            if((int)$player['club_id']===$context->clubId())throw new ValidationException(['player'=>'Ce joueur appartient déjà à votre club.']);
            $from=$player['club_id']!==null?(int)$player['club_id']:null;$to=$context->clubId();
        }else{
            if((int)$player['club_id']!==$context->clubId())throw new ValidationException(['player'=>'Vous ne pouvez vendre que vos propres joueurs.']);
            $from=$context->clubId();$to=(int)($input['to_club_id']??0);
            $club=$this->database->selectOne('SELECT id FROM clubs WHERE save_id=:save_id AND id=:club_id',['save_id'=>$context->saveId(),'club_id'=>$to]);
            if(!$club||$to===$from)throw new ValidationException(['club'=>'Club acheteur invalide.']);
        }
        $type=(string)($input['offer_type']??'purchase');if(!in_array($type,['purchase','loan'],true))$type='purchase';
        if($type==='loan'&&$from===null)throw new ValidationException(['offer'=>'Un joueur libre ne peut pas être prêté.']);
        $amount=max(0,(float)($input['amount']??0));$wage=max(0,(float)($input['wage']??0));
        if($type==='loan')$amount=0;
        if($direction==='buy'&&$type==='purchase'&&$amount>(float)$context->club()['transfer_budget'])throw new ValidationException(['amount'=>'Votre budget transferts est insuffisant.']);
        $offerId=$this->insertOffer($context,[
            'player_id'=>(int)$player['id'],'from_club_id'=>$from,'to_club_id'=>$to,'initiated_by'=>'user','offer_type'=>$type,'amount'=>$amount,
            'wage'=>$wage,'contract_years'=>max(1,min(5,(int)($input['contract_years']??3))),'signing_bonus'=>max(0,(float)($input['signing_bonus']??0)),
            'loan_duration_months'=>max(1,min(24,(int)($input['loan_duration_months']??6))),'loan_wage_share'=>max(0,min(100,(float)($input['loan_wage_share']??100))),
            'buy_option'=>!empty($input['buy_option'])?(float)$input['buy_option']:null,'parent_offer_id'=>null,
        ]);
        return $this->evaluateUserOffer($context,$offerId);
    }

    public function accept(GameContext $context,int $offerId): void
    {
        $this->assertWindow($context);$offer=$this->ownedOffer($context,$offerId);
        if($offer['status']!=='pending')throw new RuntimeException('Cette offre n’est plus disponible.');
        $player=$this->players->find($context->saveId(),(int)$offer['player_id']);
        if((int)$offer['to_club_id']===$context->clubId()&&!$this->contractAccepted($player,$offer))throw new RuntimeException('Le joueur refuse les conditions salariales proposées. Augmentez le salaire ou le projet sportif.');
        $this->complete($context,$offerId);
    }

    public function reject(GameContext $context,int $offerId): void
    {
        $offer=$this->ownedOffer($context,$offerId);
        $this->database->execute('UPDATE transfer_offers SET status=\'rejected\',responded_at=NOW() WHERE id=:id AND save_id=:save_id',['id'=>$offerId,'save_id'=>$context->saveId()]);
        $this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Offre refusée','La proposition concernant '.(int)$offer['player_id'].' a été refusée.','transfer','/saves/'.$context->saveId().'/transfers?tab=offers');
    }

    public function counter(GameContext $context,int $offerId,float $amount,float $wage): int
    {
        $this->assertWindow($context);$offer=$this->ownedOffer($context,$offerId);
        if($offer['status']!=='pending')throw new RuntimeException('Cette offre n’est plus disponible.');
        $this->database->execute('UPDATE transfer_offers SET status=\'countered\' WHERE id=:id AND save_id=:save_id',['id'=>$offerId,'save_id'=>$context->saveId()]);
        $offer['parent_offer_id']=$offerId;$offer['initiated_by']='user';$offer['amount']=max(0,$amount);$offer['wage']=max(0,$wage);unset($offer['id']);
        $newId=$this->insertOffer($context,$offer);
        return $this->evaluateUserOffer($context,$newId);
    }

    public function release(GameContext $context,int $playerId): void
    {
        $player=$this->players->find($context->saveId(),$playerId);
        if(!$player||(int)$player['club_id']!==$context->clubId())throw new RuntimeException('Joueur introuvable dans votre effectif.');
        $this->database->transaction(function()use($context,$player):void{
            $this->database->execute('UPDATE contracts SET status=\'terminated\' WHERE save_id=:save_id AND player_id=:player_id AND status=\'active\'',['save_id'=>$context->saveId(),'player_id'=>(int)$player['id']]);
            $this->database->execute('UPDATE players SET club_id=NULL WHERE save_id=:save_id AND id=:player_id',['save_id'=>$context->saveId(),'player_id'=>(int)$player['id']]);
            $this->database->insert('INSERT INTO transfers (save_id,season_id,player_id,from_club_id,to_club_id,type,fee,status,transfer_date,completed_at) VALUES (:save_id,:season_id,:player_id,:from_club,NULL,\'free\',0,\'completed\',:date,NOW())',['save_id'=>$context->saveId(),'season_id'=>(int)$context->save()['current_season_id'],'player_id'=>(int)$player['id'],'from_club'=>$context->clubId(),'date'=>$context->save()['current_date']]);
        });
        $this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Contrat résilié',$player['first_name'].' '.$player['last_name'].' est désormais joueur libre.','transfer');
    }

    public function daily(GameContext $context,string $date): int
    {
        $this->processLoanReturns($context,$date);
        $this->database->execute('UPDATE transfer_offers SET status=\'expired\' WHERE save_id=:save_id AND status=\'pending\' AND expires_at IS NOT NULL AND expires_at<:date',['save_id'=>$context->saveId(),'date'=>$date.' 23:59:59']);
        if(!$this->isWindowOpen($date))return 0;
        $count=0;
        foreach($this->ai->chooseDailyActions($context->saveId(),$date) as $action){
            $offerId=$this->insertOffer($context,['player_id'=>$action['player_id'],'from_club_id'=>$action['from_club_id'],'to_club_id'=>$action['to_club_id'],'initiated_by'=>'ai','offer_type'=>'purchase','amount'=>$action['amount'],'wage'=>$action['wage'],'contract_years'=>3,'signing_bonus'=>0,'parent_offer_id'=>null]);
            if($action['type']==='ai_transfer')$this->complete($context,$offerId);
            else{$player=$this->players->find($context->saveId(),(int)$action['player_id']);$this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Offre pour '.$player['first_name'].' '.$player['last_name'],'Un club propose '.format_money($action['amount']).'. Acceptez, refusez ou faites une contre-proposition depuis le mercato.','transfer','/saves/'.$context->saveId().'/transfers?tab=offers');}
            $count++;
        }
        return $count;
    }

    private function evaluateUserOffer(GameContext $context,int $offerId): int
    {
        $offer=$this->ownedOffer($context,$offerId);$player=$this->players->find($context->saveId(),(int)$offer['player_id']);
        if($offer['from_club_id']===null)$decision=['decision'=>'accept'];
        else{$perspective=(int)$offer['to_club_id']===$context->clubId()?'seller':'buyer';$decision=$this->ai->evaluateTransferOffer($offer,$player,$perspective);}
        if($decision['decision']==='accept'){
            if((int)$offer['to_club_id']===$context->clubId()&&!$this->contractAccepted($player,$offer)){$this->database->execute('UPDATE transfer_offers SET status=\'rejected\',message=\'Le joueur refuse le contrat proposé.\' WHERE id=:id',['id'=>$offerId]);$this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Négociation salariale échouée','Le club était d’accord, mais le joueur a refusé le salaire proposé.','transfer','/saves/'.$context->saveId().'/transfers?tab=offers');return $offerId;}
            $this->complete($context,$offerId);return $offerId;
        }
        if($decision['decision']==='counter'){
            $this->database->execute('UPDATE transfer_offers SET status=\'countered\' WHERE id=:id',['id'=>$offerId]);
            $offer['parent_offer_id']=$offerId;$offer['initiated_by']='ai';$offer['amount']=(float)$decision['amount'];unset($offer['id']);
            $newId=$this->insertOffer($context,$offer);
            $this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Contre-offre reçue','Le club réclame '.format_money($decision['amount']).' pour '.$player['first_name'].' '.$player['last_name'].'.','transfer','/saves/'.$context->saveId().'/transfers?tab=offers');
            return $newId;
        }
        $this->database->execute('UPDATE transfer_offers SET status=\'rejected\',responded_at=NOW() WHERE id=:id',['id'=>$offerId]);
        $this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Offre refusée par le club','Votre proposition pour '.$player['first_name'].' '.$player['last_name'].' a été jugée insuffisante.','transfer','/saves/'.$context->saveId().'/transfers?tab=offers');
        return $offerId;
    }

    private function complete(GameContext $context,int $offerId): void
    {
        $this->database->transaction(function()use($context,$offerId):void{
            $offer=$this->database->selectOne('SELECT * FROM transfer_offers WHERE save_id=:save_id AND id=:id FOR UPDATE',['save_id'=>$context->saveId(),'id'=>$offerId]);
            if(!$offer||$offer['status']!=='pending')throw new RuntimeException('Offre déjà traitée.');
            if((int)$offer['to_club_id']===$context->clubId()&&$offer['offer_type']==='purchase'){
                $club=$this->database->selectOne('SELECT transfer_budget FROM clubs WHERE save_id=:save_id AND id=:club_id FOR UPDATE',['save_id'=>$context->saveId(),'club_id'=>$context->clubId()]);
                if((float)$offer['amount']+(float)$offer['signing_bonus']>(float)$club['transfer_budget'])throw new RuntimeException('Budget transferts insuffisant pour finaliser cette opération.');
            }
            $date=(string)$context->save()['current_date'];$seasonId=(int)$context->save()['current_season_id'];
            $this->database->execute('UPDATE transfer_offers SET status=\'accepted\',responded_at=NOW() WHERE id=:id',['id'=>$offerId]);
            if($offer['offer_type']==='loan'){
                $transferId=$this->insertTransfer($context,$offer,'loan',$date,$seasonId);
                $this->database->insert('INSERT INTO loans (save_id,transfer_id,player_id,from_club_id,to_club_id,starts_on,ends_on,wage_share,option_to_buy) VALUES (:save_id,:transfer_id,:player_id,:from_club,:to_club,:starts_on,:ends_on,:wage_share,:option)',['save_id'=>$context->saveId(),'transfer_id'=>$transferId,'player_id'=>(int)$offer['player_id'],'from_club'=>(int)$offer['from_club_id'],'to_club'=>(int)$offer['to_club_id'],'starts_on'=>$date,'ends_on'=>(new DateTimeImmutable($date))->modify('+'.(int)$offer['loan_duration_months'].' months')->format('Y-m-d'),'wage_share'=>(float)$offer['loan_wage_share'],'option'=>$offer['buy_option']]);
                $this->database->execute('UPDATE players SET club_id=:club_id WHERE save_id=:save_id AND id=:player_id',['club_id'=>(int)$offer['to_club_id'],'save_id'=>$context->saveId(),'player_id'=>(int)$offer['player_id']]);
            }else{
                $transferId=$this->insertTransfer($context,$offer,$offer['from_club_id']===null?'free':'permanent',$date,$seasonId);
                $this->database->execute('UPDATE contracts SET status=\'terminated\' WHERE save_id=:save_id AND player_id=:player_id AND status=\'active\'',['save_id'=>$context->saveId(),'player_id'=>(int)$offer['player_id']]);
                $this->database->execute('UPDATE players SET club_id=:club_id WHERE save_id=:save_id AND id=:player_id',['club_id'=>(int)$offer['to_club_id'],'save_id'=>$context->saveId(),'player_id'=>(int)$offer['player_id']]);
                $this->database->insert('INSERT INTO contracts (save_id,player_id,club_id,starts_on,ends_on,salary,signing_bonus) VALUES (:save_id,:player_id,:club_id,:starts_on,:ends_on,:salary,:bonus)',['save_id'=>$context->saveId(),'player_id'=>(int)$offer['player_id'],'club_id'=>(int)$offer['to_club_id'],'starts_on'=>$date,'ends_on'=>(new DateTimeImmutable($date))->modify('+'.(int)$offer['contract_years'].' years')->format('Y-m-d'),'salary'=>(float)$offer['wage'],'bonus'=>(float)$offer['signing_bonus']]);
            }
            $this->database->execute('UPDATE transfer_offers SET status=\'expired\' WHERE save_id=:save_id AND player_id=:player_id AND status=\'pending\' AND id<>:id',['save_id'=>$context->saveId(),'player_id'=>(int)$offer['player_id'],'id'=>$offerId]);
            $player=$this->players->find($context->saveId(),(int)$offer['player_id']);
            $this->messages->send($context->saveId(),$context->userId(),$context->clubId(),'Transfert finalisé',$player['first_name'].' '.$player['last_name'].' : opération conclue pour '.format_money($offer['amount']).'.','transfer','/saves/'.$context->saveId().'/transfers?tab=history');
            $this->events->dispatch('transfer.completed',['save_id'=>$context->saveId(),'transfer_id'=>$transferId,'player_id'=>(int)$offer['player_id'],'from_club_id'=>$offer['from_club_id'],'to_club_id'=>$offer['to_club_id'],'fee'=>(float)$offer['amount'],'signing_bonus'=>(float)$offer['signing_bonus'],'season_id'=>$seasonId,'date'=>$date]);
        });
    }

    private function insertOffer(GameContext $context,array $offer): int
    {
        return (int)$this->database->insert('INSERT INTO transfer_offers (save_id,parent_offer_id,player_id,from_club_id,to_club_id,initiated_by,offer_type,amount,wage,contract_years,signing_bonus,loan_duration_months,loan_wage_share,buy_option,expires_at) VALUES (:save_id,:parent_id,:player_id,:from_club,:to_club,:initiated_by,:type,:amount,:wage,:years,:bonus,:duration,:wage_share,:buy_option,:expires)',['save_id'=>$context->saveId(),'parent_id'=>$offer['parent_offer_id']??null,'player_id'=>(int)$offer['player_id'],'from_club'=>$offer['from_club_id'],'to_club'=>$offer['to_club_id'],'initiated_by'=>$offer['initiated_by'],'type'=>$offer['offer_type']??'purchase','amount'=>(float)($offer['amount']??0),'wage'=>(float)($offer['wage']??0),'years'=>(int)($offer['contract_years']??3),'bonus'=>(float)($offer['signing_bonus']??0),'duration'=>$offer['loan_duration_months']??null,'wage_share'=>$offer['loan_wage_share']??null,'buy_option'=>$offer['buy_option']??null,'expires'=>(new DateTimeImmutable((string)$context->save()['current_date']))->modify('+3 days')->format('Y-m-d H:i:s')]);
    }

    private function insertTransfer(GameContext $context,array $offer,string $type,string $date,int $seasonId): int
    {
        return (int)$this->database->insert('INSERT INTO transfers (save_id,season_id,player_id,from_club_id,to_club_id,type,fee,status,transfer_date,completed_at,details) VALUES (:save_id,:season_id,:player_id,:from_club,:to_club,:type,:fee,\'completed\',:date,NOW(),:details)',['save_id'=>$context->saveId(),'season_id'=>$seasonId,'player_id'=>(int)$offer['player_id'],'from_club'=>$offer['from_club_id'],'to_club'=>$offer['to_club_id'],'type'=>$type,'fee'=>(float)$offer['amount'],'date'=>$date,'details'=>json_encode(['offer_id'=>(int)$offer['id'],'signing_bonus'=>(float)$offer['signing_bonus']],JSON_THROW_ON_ERROR)]);
    }

    private function processLoanReturns(GameContext $context,string $date): void
    {
        $loans=$this->database->select('SELECT * FROM loans WHERE save_id=:save_id AND status=\'active\' AND ends_on<=:date',['save_id'=>$context->saveId(),'date'=>$date]);
        foreach($loans as $loan){
            $this->database->execute('UPDATE loans SET status=\'completed\' WHERE id=:id AND save_id=:save_id',['id'=>(int)$loan['id'],'save_id'=>$context->saveId()]);
            $this->database->execute('UPDATE players SET club_id=:club_id WHERE save_id=:save_id AND id=:player_id',['club_id'=>(int)$loan['from_club_id'],'save_id'=>$context->saveId(),'player_id'=>(int)$loan['player_id']]);
            $this->database->insert('INSERT INTO transfers (save_id,season_id,player_id,from_club_id,to_club_id,type,fee,status,transfer_date,completed_at) VALUES (:save_id,:season_id,:player_id,:from_club,:to_club,\'loan_return\',0,\'completed\',:date,NOW())',['save_id'=>$context->saveId(),'season_id'=>(int)$context->save()['current_season_id'],'player_id'=>(int)$loan['player_id'],'from_club'=>(int)$loan['to_club_id'],'to_club'=>(int)$loan['from_club_id'],'date'=>$date]);
        }
    }

    private function contractAccepted(array $player,array $offer): bool
    {
        $expected=max((float)($player['contract_salary']??0)*1.10,(int)$player['overall_rating']*(int)$player['overall_rating']*.44);
        $club=$this->database->selectOne('SELECT reputation FROM clubs WHERE save_id=:save_id AND id=:club_id',['save_id'=>$player['save_id'],'club_id'=>(int)$offer['to_club_id']]);
        $factor=(int)$player['morale']<45?1.15:1.0;
        if((int)($club['reputation']??50)<55)$factor*=1.08;
        return (float)$offer['wage']>=$expected*$factor&&(int)$offer['contract_years']>=1&&(int)$offer['contract_years']<=5;
    }

    private function ownedOffer(GameContext $context,int $offerId): array
    {
        $offer=$this->database->selectOne('SELECT * FROM transfer_offers WHERE save_id=:save_id AND id=:id AND (from_club_id=:from_club OR to_club_id=:to_club)',['save_id'=>$context->saveId(),'id'=>$offerId,'from_club'=>$context->clubId(),'to_club'=>$context->clubId()]);
        if(!$offer)throw new RuntimeException('Offre introuvable.');return $offer;
    }
    private function assertWindow(GameContext $context): void { if(!$this->isWindowOpen((string)$context->save()['current_date']))throw new RuntimeException('Le marché des transferts est fermé à cette date.'); }
}

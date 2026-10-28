<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AIManagerInterface;
use App\Core\Config;
use App\Database\Database;

final class ClubAIManager implements AIManagerInterface
{
    public function __construct(private readonly Database $database,private readonly Config $config)
    {
    }

    public function evaluateTransferOffer(array $offer,array $player,string $perspective): array
    {
        $value=(float)$player['market_value'];$amount=(float)$offer['amount'];
        if($offer['offer_type']==='loan'){
            $accept=(int)$player['overall_rating']<78||$this->random()<.55;
            return ['decision'=>$accept?'accept':'reject'];
        }
        if($perspective==='seller'){
            $expected=$value*(float)$this->config->get('game.transfers.asking_price_ratio',1.18);
            if(!empty($player['release_clause'])&&$amount>=(float)$player['release_clause'])return ['decision'=>'accept'];
            if($amount>=$expected)return ['decision'=>'accept'];
            if($amount>=$expected*(float)$this->config->get('game.transfers.counter_offer_ratio',.72))return ['decision'=>'counter','amount'=>round($expected,2)];
            return ['decision'=>'reject'];
        }
        $maximum=$value*1.04;
        if($amount<=$maximum)return ['decision'=>'accept'];
        if($amount<=$maximum*1.3)return ['decision'=>'counter','amount'=>round($maximum,2)];
        return ['decision'=>'reject'];
    }

    public function chooseDailyActions(int $saveId,string $date): array
    {
        $save=$this->database->selectOne('SELECT club_id FROM saves WHERE id=:save_id',['save_id'=>$saveId]);$managed=(int)($save['club_id']??0);$actions=[];
        if($this->random()<(float)$this->config->get('game.transfers.ai_daily_transfer_chance',.32)){
            $player=$this->database->selectOne('SELECT p.*,ct.salary AS contract_salary FROM players p LEFT JOIN contracts ct ON ct.player_id=p.id AND ct.status=\'active\' WHERE p.save_id=:save_id AND p.club_id IS NOT NULL AND p.club_id<>:managed AND p.overall_rating<82 ORDER BY RAND() LIMIT 1',['save_id'=>$saveId,'managed'=>$managed]);
            if($player){$buyer=$this->database->selectOne('SELECT id FROM clubs WHERE save_id=:save_id AND id<>:club_id ORDER BY RAND() LIMIT 1',['save_id'=>$saveId,'club_id'=>(int)$player['club_id']]);if($buyer)$actions[]=['type'=>'ai_transfer','player_id'=>(int)$player['id'],'from_club_id'=>(int)$player['club_id'],'to_club_id'=>(int)$buyer['id'],'amount'=>round((float)$player['market_value']*(.85+$this->random()*.35),2),'wage'=>round((float)($player['contract_salary']??2000)*1.08,2)];}
        }
        if($managed&&$this->random()<(float)$this->config->get('game.transfers.ai_daily_user_offer_chance',.12)){
            $player=$this->database->selectOne('SELECT p.*,ct.salary AS contract_salary FROM players p JOIN contracts ct ON ct.player_id=p.id AND ct.status=\'active\' WHERE p.save_id=:save_id AND p.club_id=:managed ORDER BY RAND() LIMIT 1',['save_id'=>$saveId,'managed'=>$managed]);
            if($player){$buyer=$this->database->selectOne('SELECT id FROM clubs WHERE save_id=:save_id AND id<>:managed ORDER BY RAND() LIMIT 1',['save_id'=>$saveId,'managed'=>$managed]);if($buyer)$actions[]=['type'=>'user_offer','player_id'=>(int)$player['id'],'from_club_id'=>$managed,'to_club_id'=>(int)$buyer['id'],'amount'=>round((float)$player['market_value']*(.75+$this->random()*.45),2),'wage'=>round((float)$player['contract_salary']*1.15,2)];}
        }
        return $actions;
    }

    private function random(): float { return random_int(0,1000000)/1000000; }
}

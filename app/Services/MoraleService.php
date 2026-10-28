<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class MoraleService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function afterMatch(int $saveId, array $fixture, array $state): void
    {
        foreach (['home','away'] as $side) {
            $clubId = (int) $fixture[$side.'_club_id'];
            $own = (int) $state[$side.'_score']; $otherSide = $side === 'home' ? 'away' : 'home';
            $other = (int) $state[$otherSide.'_score'];
            $moraleDelta = $own > $other ? 5 : ($own === $other ? 1 : -4);
            $formDelta = $own > $other ? 4 : ($own === $other ? 0 : -3);
            $ids = array_map(static fn($e)=>(int)$e['player_id'],$state['sides'][$side]['tactic']['lineup']);
            $this->updateGroup($saveId,$clubId,$ids,9,$moraleDelta,$formDelta);
            $subIds = array_map(static fn($e)=>(int)$e['player_id'],$state['sides'][$side]['tactic']['substitutes']);
            $this->updateGroup($saveId,$clubId,$subIds,2,$moraleDelta,$formDelta);
        }
    }

    public function recoverDaily(int $saveId, string $date): void
    {
        $this->database->execute(
            'UPDATE players SET fatigue = GREATEST(0, fatigue - 7),
             morale = GREATEST(20, LEAST(100, morale + IF(morale < 60, 1, 0))),
             injury_type = IF(injury_until IS NOT NULL AND injury_until <= :injury_date, NULL, injury_type),
             injury_until = IF(injury_until IS NOT NULL AND injury_until <= :clear_date, NULL, injury_until)
             WHERE save_id = :save_id',
            ['injury_date' => $date, 'clear_date' => $date, 'save_id' => $saveId]
        );
    }

    private function updateGroup(int $saveId,int $clubId,array $ids,int $fatigue,int $morale,int $form): void
    {
        if($ids===[])return;
        $bindings=['save_id'=>$saveId,'club_id'=>$clubId,'fatigue'=>$fatigue,'morale'=>$morale,'form'=>$form];
        $placeholders=[];
        foreach(array_values($ids) as $index=>$id){$key='player_'.$index;$placeholders[]=':'.$key;$bindings[$key]=$id;}
        $this->database->execute(
            'UPDATE players SET fatigue = LEAST(100, fatigue + :fatigue),
             morale = GREATEST(10, LEAST(100, morale + :morale)), form = GREATEST(10, LEAST(100, form + :form))
             WHERE save_id = :save_id AND club_id = :club_id AND id IN ('.implode(',',$placeholders).')',
            $bindings
        );
    }
}

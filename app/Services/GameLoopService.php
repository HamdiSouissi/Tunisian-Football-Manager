<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventDispatcherInterface;
use App\Database\Database;
use App\Security\ValidationException;
use DateTimeImmutable;

final class GameLoopService
{
    public function __construct(
        private readonly Database $database,
        private readonly MatchService $matches,
        private readonly TrainingService $training,
        private readonly FinanceService $finances,
        private readonly TransferService $transfers,
        private readonly MoraleService $morale,
        private readonly SeasonService $seasons,
        private readonly EventDispatcherInterface $events
    ) {
    }

    /** @return array<string,int> */
    public function advance(GameContext $context,int $days=1): array
    {
        if($days<1||$days>7)throw new ValidationException(['days'=>'Vous pouvez avancer de un à sept jours à la fois.']);
        if($context->save()['status']!=='active')throw new ValidationException(['save'=>'Cette carrière est archivée ou terminée.']);
        $live=$this->database->selectOne('SELECT id FROM fixtures WHERE save_id=:save_id AND (home_club_id=:home_club_id OR away_club_id=:away_club_id) AND status IN (\'live\',\'halftime\') LIMIT 1',['save_id'=>$context->saveId(),'home_club_id'=>$context->clubId(),'away_club_id'=>$context->clubId()]);
        if($live)throw new ValidationException(['match'=>'Terminez le match en cours avant de continuer.']);
        $pending=$this->database->selectOne(
            'SELECT id FROM fixtures WHERE save_id=:save_id AND (home_club_id=:home_club_id OR away_club_id=:away_club_id)
             AND status=\'scheduled\' AND DATE(scheduled_at)<=:current_date LIMIT 1',
            ['save_id'=>$context->saveId(),'home_club_id'=>$context->clubId(),'away_club_id'=>$context->clubId(),'current_date'=>$context->save()['current_date']]
        );
        if($pending)throw new ValidationException(['match'=>'Jouez le match de votre club avant de continuer.']);
        $totals=['days'=>0,'matches'=>0,'training'=>0,'transfers'=>0,'season'=>0,'awaiting_match'=>0];
        $stopForMatch=false;
        for($i=0;$i<$days;$i++){
            if($context->save()['status']!=='active')break;
            $date=$this->nextDate((string)$context->save()['current_date']);
            $this->database->transaction(function()use($context,$date,&$totals,&$stopForMatch):void{
                $saveId=$context->saveId();
                $this->database->execute('UPDATE saves SET `current_date`=:date WHERE id=:save_id AND status=\'active\'',['date'=>$date,'save_id'=>$saveId]);
                $context->refresh();
                $this->morale->recoverDaily($saveId,$date);
                $fixtures=$this->database->select('SELECT id,home_club_id,away_club_id FROM fixtures WHERE save_id=:save_id AND season_id=:season_id AND DATE(scheduled_at)=:date AND status=\'scheduled\' ORDER BY id',['save_id'=>$saveId,'season_id'=>(int)$context->save()['current_season_id'],'date'=>$date]);
                foreach($fixtures as $fixture){
                    $isUserMatch=in_array($context->clubId(),[(int)$fixture['home_club_id'],(int)$fixture['away_club_id']],true);
                    if($isUserMatch){
                        // Le match du club dirigé attend une action explicite du joueur.
                        $totals['awaiting_match']=(int)$fixture['id'];
                        $stopForMatch=true;
                        continue;
                    }
                    $this->matches->simulate($saveId,(int)$fixture['id'],$context);
                    $totals['matches']++;
                }
                $context->refresh();
                if($context->save()['status']!=='active'){$totals['days']++;return;}
                if((new DateTimeImmutable($date))->format('N')==='1'){
                    $this->training->applyWeek($saveId,$date,$context);$totals['training']++;
                    $this->events->dispatch('week.advanced',['save_id'=>$saveId,'date'=>$date,'club_id'=>$context->clubId()]);
                }
                $this->finances->daily($saveId,$date);
                $totals['transfers']+=$this->transfers->daily($context,$date);
                $this->events->dispatch('day.advanced',['save_id'=>$saveId,'date'=>$date]);
                if($this->seasons->finishIfNeeded($context,$date))$totals['season']++;
                $totals['days']++;
            });
            $context->refresh();
            if($stopForMatch)break;
        }
        return $totals;
    }

    /** DateTimeImmutable ne fournit pas de méthode addDay(). */
    private function nextDate(string $currentDate): string
    {
        return (new DateTimeImmutable($currentDate))->modify('+1 day')->format('Y-m-d');
    }
}

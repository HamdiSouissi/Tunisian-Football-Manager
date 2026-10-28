<?php

declare(strict_types=1);

namespace App\Engine;

use DateTimeImmutable;

/** Construction calendaire pure, indépendante de la base et de la simulation. */
final class CalendarEngine
{
    private const MONTHS = [1=>'janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
    private const DAYS = [1=>'Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi','Dimanche'];

    public function month(string $anchor, array $fixtures, int $clubId, string $currentDate): array
    {
        $date = new DateTimeImmutable($anchor);
        $first = $date->modify('first day of this month');
        $start = $first->modify('monday this week');
        $cells = [];
        for ($index = 0; $index < 42; $index++) {
            $day = $start->modify("+{$index} days");
            $cells[] = $this->cell($day, $fixtures, $clubId, $currentDate, $day->format('m') === $first->format('m'));
        }
        return [
            'view'=>'month', 'label'=>self::MONTHS[(int)$first->format('n')] . ' ' . $first->format('Y'),
            'start'=>$cells[0]['date'], 'end'=>$cells[41]['date'], 'cells'=>$cells,
            'previous'=>$first->modify('-1 month')->format('Y-m-d'),
            'next'=>$first->modify('+1 month')->format('Y-m-d'),
            'days'=>array_values(self::DAYS),
        ];
    }

    public function week(string $anchor, array $fixtures, int $clubId, string $currentDate): array
    {
        $date = new DateTimeImmutable($anchor);
        $monday = $date->modify('monday this week');
        $cells = [];
        for ($index = 0; $index < 7; $index++) {
            $day = $monday->modify("+{$index} days");
            $cell = $this->cell($day, $fixtures, $clubId, $currentDate, true);
            $cell['weekday'] = self::DAYS[(int)$day->format('N')];
            $cells[] = $cell;
        }
        return [
            'view'=>'week', 'label'=>'Semaine du ' . $monday->format('d/m/Y'),
            'start'=>$cells[0]['date'], 'end'=>$cells[6]['date'], 'cells'=>$cells,
            'previous'=>$monday->modify('-7 days')->format('Y-m-d'),
            'next'=>$monday->modify('+7 days')->format('Y-m-d'),
            'days'=>array_values(self::DAYS),
        ];
    }

    private function cell(DateTimeImmutable $date, array $fixtures, int $clubId, string $currentDate, bool $inPeriod): array
    {
        $key = $date->format('Y-m-d');
        $dayFixtures = array_values(array_filter($fixtures, static fn (array $fixture): bool => substr((string)$fixture['scheduled_at'], 0, 10) === $key));
        foreach ($dayFixtures as &$fixture) {
            $fixture['is_own'] = in_array($clubId, [(int)$fixture['home_club_id'], (int)$fixture['away_club_id']], true);
        }
        unset($fixture);
        return ['date'=>$key, 'day'=>(int)$date->format('j'), 'in_period'=>$inPeriod, 'is_today'=>$key===$currentDate, 'fixtures'=>$dayFixtures];
    }
}

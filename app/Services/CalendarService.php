<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Security\ValidationException;
use DateTimeImmutable;

/** Orchestration du calendrier : le monde avance exclusivement via GameLoopService. */
final class CalendarService
{
    public function __construct(private readonly Database $database, private readonly GameLoopService $gameLoop)
    {
    }

    public function nextFixture(GameContext $context): ?array
    {
        return $this->database->selectOne(
            "SELECT f.*,h.name AS home_name,h.short_name AS home_short,h.slug AS home_slug,h.primary_color AS home_color,
                    a.name AS away_name,a.short_name AS away_short,a.slug AS away_slug,a.primary_color AS away_color
             FROM fixtures f JOIN clubs h ON h.id=f.home_club_id JOIN clubs a ON a.id=f.away_club_id
             WHERE f.save_id=:save_id AND (f.home_club_id=:home_id OR f.away_club_id=:away_id)
               AND f.status IN ('scheduled','live','halftime') ORDER BY f.scheduled_at,f.id LIMIT 1",
            ['save_id'=>$context->saveId(),'home_id'=>$context->clubId(),'away_id'=>$context->clubId()]
        );
    }

    public function recentResults(GameContext $context, int $limit = 5): array
    {
        $limit = max(1, min(10, $limit));
        return $this->database->select(
            "SELECT f.*,h.name AS home_name,h.short_name AS home_short,h.slug AS home_slug,
                    a.name AS away_name,a.short_name AS away_short,a.slug AS away_slug
             FROM fixtures f JOIN clubs h ON h.id=f.home_club_id JOIN clubs a ON a.id=f.away_club_id
             WHERE f.save_id=:save_id AND (f.home_club_id=:home_id OR f.away_club_id=:away_id) AND f.status='finished'
             ORDER BY f.scheduled_at DESC,f.id DESC LIMIT {$limit}",
            ['save_id'=>$context->saveId(),'home_id'=>$context->clubId(),'away_id'=>$context->clubId()]
        );
    }

    public function advanceUntil(GameContext $context, string $targetDate): array
    {
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $targetDate);
        if (!$target || $target->format('Y-m-d') !== $targetDate) {
            throw new ValidationException(['target_date'=>'Choisissez une date valide.']);
        }
        $current = new DateTimeImmutable((string)$context->save()['current_date']);
        if ($target < $current) {
            throw new ValidationException(['target_date'=>'La date cible doit être postérieure à la date actuelle.']);
        }
        if ($current->diff($target)->days > 120) {
            throw new ValidationException(['target_date'=>'La simulation est limitée à 120 jours par opération.']);
        }
        $totals = ['days'=>0,'matches'=>0,'training'=>0,'transfers'=>0,'season'=>0,'awaiting_match'=>0];
        while ($context->save()['current_date'] < $targetDate && $context->save()['status'] === 'active') {
            $remaining = (new DateTimeImmutable((string)$context->save()['current_date']))->diff($target)->days;
            $result = $this->gameLoop->advance($context, min(7, max(1, $remaining)));
            foreach (['days','matches','training','transfers','season'] as $key) {
                $totals[$key] += (int)$result[$key];
            }
            $totals['awaiting_match'] = (int)$result['awaiting_match'];
            if ($totals['awaiting_match'] > 0 || (int)$result['days'] === 0) break;
        }
        $totals['target_date'] = $targetDate;
        $totals['reached'] = $context->save()['current_date'] === $targetDate;
        return $totals;
    }

    public function advanceToNextMatch(GameContext $context): array
    {
        $fixture = $this->nextFixture($context);
        if (!$fixture) {
            throw new ValidationException(['calendar'=>'Aucune rencontre à venir dans cette sauvegarde.']);
        }
        if (in_array($fixture['status'], ['live','halftime'], true) || substr((string)$fixture['scheduled_at'], 0, 10) <= $context->save()['current_date']) {
            return ['days'=>0,'matches'=>0,'training'=>0,'transfers'=>0,'season'=>0,'awaiting_match'=>(int)$fixture['id'],'target_date'=>substr((string)$fixture['scheduled_at'],0,10),'reached'=>true];
        }
        return $this->advanceUntil($context, substr((string)$fixture['scheduled_at'], 0, 10));
    }
}

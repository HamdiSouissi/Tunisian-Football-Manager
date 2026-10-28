<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Models\PlayerRepository;

final class DashboardService
{
    public function __construct(private readonly Database $database, private readonly PlayerRepository $players, private readonly MessageService $messages)
    {
    }

    public function summary(GameContext $context): array
    {
        $saveId = $context->saveId();
        $clubId = $context->clubId();
        $seasonId = (int) $context->save()['current_season_id'];
        $next = $this->database->selectOne(
            'SELECT f.*, h.name AS home_name, a.name AS away_name, h.primary_color AS home_color, a.primary_color AS away_color
             FROM fixtures f JOIN clubs h ON h.id = f.home_club_id JOIN clubs a ON a.id = f.away_club_id
             WHERE f.save_id = :save_id AND f.status IN (\'scheduled\',\'live\',\'halftime\')
             AND (f.home_club_id = :home_club OR f.away_club_id = :away_club)
             ORDER BY f.scheduled_at LIMIT 1',
            ['save_id' => $saveId, 'home_club' => $clubId, 'away_club' => $clubId]
        );
        $recent = $this->database->select(
            'SELECT f.*, h.name AS home_name, a.name AS away_name FROM fixtures f
             JOIN clubs h ON h.id = f.home_club_id JOIN clubs a ON a.id = f.away_club_id
             WHERE f.save_id = :save_id AND f.status = \'finished\' AND (f.home_club_id = :home_club OR f.away_club_id = :away_club)
             ORDER BY f.scheduled_at DESC LIMIT 5',
            ['save_id' => $saveId, 'home_club' => $clubId, 'away_club' => $clubId]
        );
        $standing = $this->database->selectOne(
            'SELECT st.*, l.name AS league_name, l.level FROM standings st JOIN leagues l ON l.id = st.league_id
             WHERE st.save_id = :save_id AND st.season_id = :season_id AND st.club_id = :club_id',
            ['save_id' => $saveId, 'season_id' => $seasonId, 'club_id' => $clubId]
        );
        $objective = $this->database->selectOne(
            'SELECT * FROM board_objectives WHERE save_id = :save_id AND season_id = :season_id AND club_id = :club_id AND is_primary = 1 LIMIT 1',
            ['save_id' => $saveId, 'season_id' => $seasonId, 'club_id' => $clubId]
        );
        $squad = $this->players->forClub($saveId, $clubId);
        return [
            'next' => $next, 'recent' => $recent, 'standing' => $standing, 'objective' => $objective,
            'topPlayers' => array_slice($squad, 0, 5),
            'injuries' => array_values(array_filter($squad, static fn ($p) => !empty($p['injury_type']))),
            'messages' => array_slice($this->messages->inbox($context), 0, 4),
        ];
    }
}

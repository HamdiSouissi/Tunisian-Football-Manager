<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class LeagueService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function recordResult(int $saveId, array $fixture): void
    {
        $home = (int) $fixture['home_score']; $away = (int) $fixture['away_score'];
        $homePoints = $home > $away ? 3 : ($home === $away ? 1 : 0);
        $awayPoints = $away > $home ? 3 : ($home === $away ? 1 : 0);
        $this->database->execute(
            'UPDATE standings SET played = played + 1, wins = wins + :home_wins, draws = draws + :home_draws, losses = losses + :home_losses,
             goals_for = goals_for + :home_for, goals_against = goals_against + :home_against,
             goal_difference = goal_difference + :home_diff, points = points + :home_points,
             form = RIGHT(CONCAT(form, :home_form), 5)
             WHERE save_id = :save_id AND season_id = :season_id AND club_id = :home_club',
            ['home_wins' => $home > $away ? 1 : 0, 'home_draws' => $home === $away ? 1 : 0, 'home_losses' => $home < $away ? 1 : 0,
             'home_for' => $home, 'home_against' => $away, 'home_diff' => $home - $away, 'home_points' => $homePoints,
             'home_form' => $home > $away ? 'W' : ($home === $away ? 'D' : 'L'), 'save_id' => $saveId,
             'season_id' => (int) $fixture['season_id'], 'home_club' => (int) $fixture['home_club_id']]
        );
        $this->database->execute(
            'UPDATE standings SET played = played + 1, wins = wins + :away_wins, draws = draws + :away_draws, losses = losses + :away_losses,
             goals_for = goals_for + :away_for, goals_against = goals_against + :away_against,
             goal_difference = goal_difference + :away_diff, points = points + :away_points,
             form = RIGHT(CONCAT(form, :away_form), 5)
             WHERE save_id = :save_id AND season_id = :season_id AND club_id = :away_club',
            ['away_wins' => $away > $home ? 1 : 0, 'away_draws' => $home === $away ? 1 : 0, 'away_losses' => $away < $home ? 1 : 0,
             'away_for' => $away, 'away_against' => $home, 'away_diff' => $away - $home, 'away_points' => $awayPoints,
             'away_form' => $away > $home ? 'W' : ($home === $away ? 'D' : 'L'), 'save_id' => $saveId,
             'season_id' => (int) $fixture['season_id'], 'away_club' => (int) $fixture['away_club_id']]
        );
        $this->recalculate($saveId, (int) $fixture['season_id'], (int) $fixture['league_id']);
    }

    public function recalculate(int $saveId, int $seasonId, int $leagueId): void
    {
        $rows = $this->database->select(
            'SELECT id FROM standings WHERE save_id = :save_id AND season_id = :season_id AND league_id = :league_id
             ORDER BY points DESC, goal_difference DESC, goals_for DESC, wins DESC, id',
            ['save_id' => $saveId, 'season_id' => $seasonId, 'league_id' => $leagueId]
        );
        foreach ($rows as $index => $row) {
            $this->database->execute('UPDATE standings SET position = :position WHERE id = :id AND save_id = :save_id', ['position' => $index + 1, 'id' => (int) $row['id'], 'save_id' => $saveId]);
        }
    }

    public function table(int $saveId, int $seasonId, int $leagueId): array
    {
        return $this->database->select(
            'SELECT st.*, c.name AS club_name, c.short_name, c.primary_color FROM standings st JOIN clubs c ON c.id = st.club_id
             WHERE st.save_id = :save_id AND st.season_id = :season_id AND st.league_id = :league_id ORDER BY st.position, st.points DESC',
            ['save_id' => $saveId, 'season_id' => $seasonId, 'league_id' => $leagueId]
        );
    }
}

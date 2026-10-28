<?php

declare(strict_types=1);

namespace App\Models;

use App\Database\Database;

final class PlayerRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function forClub(int $saveId, int $clubId, bool $includeRetired = false): array
    {
        $sql = 'SELECT ' . $this->selectColumns() . ' FROM players p
                LEFT JOIN player_attributes a ON a.player_id = p.id AND a.save_id = p.save_id
                LEFT JOIN contracts ct ON ct.player_id = p.id AND ct.save_id = p.save_id AND ct.status = \'active\'
                WHERE p.save_id = :save_id AND p.club_id = :club_id';
        if (!$includeRetired) {
            $sql .= ' AND p.is_retired = 0';
        }
        $sql .= ' ORDER BY p.overall_rating DESC, p.last_name, p.first_name';
        return $this->database->select($sql, ['save_id' => $saveId, 'club_id' => $clubId]);
    }

    public function find(int $saveId, int $playerId): ?array
    {
        return $this->database->selectOne(
            'SELECT ' . $this->selectColumns() . ', c.name AS club_name, c.primary_color AS club_color
             FROM players p
             LEFT JOIN player_attributes a ON a.player_id = p.id AND a.save_id = p.save_id
             LEFT JOIN clubs c ON c.id = p.club_id AND c.save_id = p.save_id
             LEFT JOIN contracts ct ON ct.player_id = p.id AND ct.save_id = p.save_id AND ct.status = \'active\'
             WHERE p.save_id = :save_id AND p.id = :player_id LIMIT 1',
            ['save_id' => $saveId, 'player_id' => $playerId]
        );
    }

    public function search(int $saveId, array $filters): array
    {
        $where = ['p.save_id = :save_id', 'p.is_retired = 0'];
        $bindings = ['save_id' => $saveId];
        if (!empty($filters['position'])) {
            $where[] = '(p.primary_position = :position OR JSON_CONTAINS(p.secondary_positions, JSON_QUOTE(:secondary_position)))';
            $bindings['position'] = $filters['position'];
            $bindings['secondary_position'] = $filters['position'];
        }
        if (!empty($filters['club_id'])) {
            $where[] = 'p.club_id = :club_id';
            $bindings['club_id'] = (int) $filters['club_id'];
        }
        if (!empty($filters['free_agent'])) {
            $where[] = 'p.club_id IS NULL';
        }
        if (!empty($filters['min_ovr'])) {
            $where[] = 'p.overall_rating >= :min_ovr';
            $bindings['min_ovr'] = (int) $filters['min_ovr'];
        }
        if (!empty($filters['max_value'])) {
            $where[] = 'p.market_value <= :max_value';
            $bindings['max_value'] = (float) $filters['max_value'];
        }
        if (!empty($filters['max_age'])) {
            $where[] = 'TIMESTAMPDIFF(YEAR, p.birth_date, :age_date) <= :max_age';
            $bindings['age_date'] = $filters['date'];
            $bindings['max_age'] = (int) $filters['max_age'];
        }
        if (!empty($filters['contract'])) {
            if ($filters['contract'] === 'expiring') {
                $where[] = 'ct.ends_on <= DATE_ADD(:contract_date, INTERVAL 12 MONTH)';
                $bindings['contract_date'] = $filters['date'];
            } elseif ($filters['contract'] === 'free') {
                $where[] = 'ct.id IS NULL';
            }
        }
        if (!empty($filters['q'])) {
            $where[] = '(p.first_name LIKE :first_query OR p.last_name LIKE :last_query)';
            $bindings['first_query'] = '%' . $filters['q'] . '%';
            $bindings['last_query'] = '%' . $filters['q'] . '%';
        }

        return $this->database->select(
            'SELECT ' . $this->selectColumns() . ', c.name AS club_name FROM players p
             LEFT JOIN player_attributes a ON a.player_id = p.id AND a.save_id = p.save_id
             LEFT JOIN clubs c ON c.id = p.club_id AND c.save_id = p.save_id
             LEFT JOIN contracts ct ON ct.player_id = p.id AND ct.save_id = p.save_id AND ct.status = \'active\'
             WHERE ' . implode(' AND ', $where) . ' ORDER BY p.overall_rating DESC, p.market_value DESC LIMIT 250',
            $bindings
        );
    }

    private function selectColumns(): string
    {
        $attributes = ['passing','dribbling','shooting','first_touch','tackling','vision','composure','work_rate','positioning','pace','stamina','strength','jumping','reflexes','handling','aerial_reach','command_of_area','kicking'];
        return 'p.*, a.' . implode(', a.', $attributes) . ',
            ct.id AS contract_id, ct.starts_on AS contract_starts, ct.ends_on AS contract_ends,
            ct.salary AS contract_salary, ct.squad_role, ct.release_clause';
    }
}

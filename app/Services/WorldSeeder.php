<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Database\Database;
use App\Security\ValidationException;
use DateTimeImmutable;
use RuntimeException;

final class WorldSeeder
{
    public function __construct(
        private readonly Database $database,
        private readonly ClubCatalog $catalog,
        private readonly PlayerFactory $playerFactory,
        private readonly FixtureGenerator $fixtureGenerator,
        private readonly TacticService $tactics,
        private readonly MessageService $messages,
        private readonly Config $config
    ) {
    }

    public function createSave(int $userId, string $name, string $managerName, string $difficulty, int $division, string $clubSlug): int
    {
        $name = trim($name);
        $managerName = trim($managerName);
        $difficulties = (array) $this->config->get('game.difficulties', []);
        if ($name === '' || strlen($name) > 120 || $managerName === '' || strlen($managerName) > 120) {
            throw new ValidationException(['name' => 'Le nom de la partie et celui de l’entraîneur sont requis (120 caractères maximum).']);
        }
        if (!isset($difficulties[$difficulty])) {
            throw new ValidationException(['difficulty' => 'Difficulté invalide.']);
        }
        try {
            $selected = $this->catalog->find($clubSlug, $division);
        } catch (RuntimeException $exception) {
            throw new ValidationException(['club' => $exception->getMessage()]);
        }

        $year = (int) date('Y');
        if ((int) date('n') < 7) {
            $year--;
        }
        $seasonStart = new DateTimeImmutable($year . '-07-01');
        $seasonEnd = new DateTimeImmutable(($year + 1) . '-05-31');

        return $this->database->transaction(function () use ($userId, $name, $managerName, $difficulty, $selected, $seasonStart, $seasonEnd, $year): int {
            // La difficulté est explicitement injectée, sans dépendance aux superglobales HTTP.
            $saveId = (int) $this->database->insert(
                'INSERT INTO saves (user_id, name, manager_name, difficulty, `current_date`) VALUES (:user_id, :name, :manager_name, :difficulty, :current_date)',
                ['user_id' => $userId, 'name' => $name, 'manager_name' => $managerName, 'difficulty' => $difficulty, 'current_date' => $seasonStart->format('Y-m-d')]
            );
            $seasonId = (int) $this->database->insert(
                'INSERT INTO seasons (save_id, name, starts_on, ends_on, status) VALUES (:save_id, :name, :starts_on, :ends_on, \'active\')',
                ['save_id' => $saveId, 'name' => $year . '-' . ($year + 1), 'starts_on' => $seasonStart->format('Y-m-d'), 'ends_on' => $seasonEnd->format('Y-m-d')]
            );

            $clubIds = [];
            $leagueIds = [];
            $managedClubId = null;
            foreach ($this->catalog->leagues() as $league) {
                $level = (int) $league['level'];
                $leagueId = (int) $this->database->insert(
                    'INSERT INTO leagues (save_id, name, short_name, slug, level, team_count) VALUES (:save_id, :name, :short_name, :slug, :level, :team_count)',
                    ['save_id' => $saveId, 'name' => $league['name'], 'short_name' => $league['short_name'], 'slug' => $league['slug'], 'level' => $level, 'team_count' => count($league['clubs'])]
                );
                $leagueIds[$level] = $leagueId;
                $clubIds[$level] = [];

                foreach ($league['clubs'] as $club) {
                    $isManaged = $club['slug'] === $selected['slug'] && (int) $selected['division'] === $level;
                    $multiplier = $isManaged ? (float) $this->config->get('game.difficulties.' . $difficulty . '.budget_multiplier', 1.0) : 1.0;
                    $reputation = (int) $club['reputation'];
                    $balance = round($reputation * $reputation * 320 * $multiplier, 2);
                    $transferBudget = round($reputation * $reputation * 210 * $multiplier, 2);
                    $wageBudget = round($reputation * $reputation * 5.5 * $multiplier, 2);
                    $clubId = (int) $this->database->insert(
                        'INSERT INTO clubs (save_id, league_id, name, short_name, slug, city, stadium_name, stadium_capacity,
                         primary_color, secondary_color, reputation, balance, transfer_budget, wage_budget)
                         VALUES (:save_id, :league_id, :name, :short_name, :slug, :city, :stadium, :capacity,
                         :primary_color, :secondary_color, :reputation, :balance, :transfer_budget, :wage_budget)',
                        ['save_id' => $saveId, 'league_id' => $leagueId, 'name' => $club['name'], 'short_name' => $club['short_name'], 'slug' => $club['slug'],
                         'city' => $club['city'], 'stadium' => $club['stadium'], 'capacity' => $club['capacity'], 'primary_color' => $club['primary'],
                         'secondary_color' => $club['secondary'], 'reputation' => $reputation, 'balance' => $balance, 'transfer_budget' => $transferBudget, 'wage_budget' => $wageBudget]
                    );
                    $clubIds[$level][] = $clubId;
                    if ($isManaged) {
                        $managedClubId = $clubId;
                    }
                    $this->seedClub($saveId, $seasonId, $clubId, $leagueId, $level, $reputation, $seasonStart);
                }
            }

            if ($managedClubId === null) {
                throw new RuntimeException('Impossible de déterminer le club dirigé.');
            }
            $this->seedFreeAgents($saveId, $seasonStart);
            $this->seedFixtures($saveId, $seasonId, $leagueIds, $clubIds, $year);
            foreach ($clubIds as $ids) {
                foreach ($ids as $index => $clubId) {
                    $this->database->execute('UPDATE standings SET position=:position WHERE save_id=:save_id AND season_id=:season_id AND club_id=:club_id', ['position'=>$index+1,'save_id'=>$saveId,'season_id'=>$seasonId,'club_id'=>$clubId]);
                }
            }
            $this->database->execute(
                'UPDATE saves SET club_id = :club_id, current_season_id = :season_id WHERE id = :save_id',
                ['club_id' => $managedClubId, 'season_id' => $seasonId, 'save_id' => $saveId]
            );
            $this->database->insert(
                'INSERT INTO settings (save_id, setting_key, setting_value) VALUES (:save_id, :setting_key, :setting_value)',
                ['save_id' => $saveId, 'setting_key' => 'game_version', 'setting_value' => json_encode('1.0.0', JSON_THROW_ON_ERROR)]
            );
            $this->messages->send($saveId, $userId, $managedClubId, 'Bienvenue au club', 'Le président vous souhaite la bienvenue. Consultez les objectifs, préparez votre tactique et utilisez Continuer pour avancer dans le calendrier.', 'board', '/saves/' . $saveId);
            return $saveId;
        });
    }

    private function seedClub(int $saveId, int $seasonId, int $clubId, int $leagueId, int $level, int $reputation, DateTimeImmutable $seasonStart): void
    {
        $squad = [];
        foreach (PlayerFactory::SQUAD_POSITIONS as $index => $position) {
            $age = $index < 3 ? random_int(17, 22) : random_int(20, 35);
            $target = max(38, min(88, $reputation - random_int(10, 24) + ($level === 2 ? -4 : 0)));
            $data = $this->playerFactory->make($position, $age, $target, null, 'Tunisia', $seasonStart);
            $playerId = $this->insertPlayer($saveId, $clubId, $data, $seasonStart, true);
            $squad[] = array_merge($data['player'], $data['attributes'], ['id' => $playerId]);
        }
        $this->tactics->createDefault($saveId, $clubId, $squad);
        $this->database->insert(
            'INSERT INTO standings (save_id, season_id, league_id, club_id) VALUES (:save_id, :season_id, :league_id, :club_id)',
            ['save_id' => $saveId, 'season_id' => $seasonId, 'league_id' => $leagueId, 'club_id' => $clubId]
        );
        $this->database->insert(
            'INSERT INTO training_plans (save_id, club_id, week_start, focus, intensity) VALUES (:save_id, :club_id, :week_start, :focus, :intensity)',
            ['save_id' => $saveId, 'club_id' => $clubId, 'week_start' => $seasonStart->modify('monday this week')->format('Y-m-d'), 'focus' => 'tactical', 'intensity' => 'medium']
        );
        foreach (['assistant','fitness_coach','doctor'] as $role) {
            $quality = max(35, min(95, $reputation + random_int(-15, 8)));
            $this->database->insert(
                'INSERT INTO staff (save_id, club_id, first_name, last_name, role, quality, wage, contract_ends_on)
                 VALUES (:save_id, :club_id, :first_name, :last_name, :role, :quality, :wage, :contract_end)',
                ['save_id' => $saveId, 'club_id' => $clubId, 'first_name' => 'Staff', 'last_name' => ucfirst(str_replace('_', ' ', $role)), 'role' => $role,
                 'quality' => $quality, 'wage' => $quality * 18, 'contract_end' => $seasonStart->modify('+3 years')->format('Y-m-d')]
            );
        }
        $objective = $level === 2 ? ($reputation >= 54 ? 'promotion' : 'top_half') : ($reputation >= 86 ? 'title' : ($reputation >= 75 ? 'top_three' : ($reputation >= 64 ? 'top_half' : 'avoid_relegation')));
        $this->database->insert(
            'INSERT INTO board_objectives (save_id, season_id, club_id, objective) VALUES (:save_id, :season_id, :club_id, :objective)',
            ['save_id' => $saveId, 'season_id' => $seasonId, 'club_id' => $clubId, 'objective' => $objective]
        );
    }

    private function insertPlayer(int $saveId, ?int $clubId, array $data, DateTimeImmutable $seasonStart, bool $withContract): int
    {
        $player = $data['player'];
        $playerId = (int) $this->database->insert(
            'INSERT INTO players (save_id, club_id, first_name, last_name, birth_date, nationality, primary_position,
             secondary_positions, preferred_foot, height_cm, weight_kg, market_value, overall_rating, potential, form, morale, fatigue)
             VALUES (:save_id, :club_id, :first_name, :last_name, :birth_date, :nationality, :position, :secondary,
             :foot, :height, :weight, :value, :overall, :potential, :form, :morale, :fatigue)',
            ['save_id' => $saveId, 'club_id' => $clubId, 'first_name' => $player['first_name'], 'last_name' => $player['last_name'],
             'birth_date' => $player['birth_date'], 'nationality' => $player['nationality'], 'position' => $player['primary_position'],
             'secondary' => $player['secondary_positions'], 'foot' => $player['preferred_foot'], 'height' => $player['height_cm'],
             'weight' => $player['weight_kg'], 'value' => $player['market_value'], 'overall' => $player['overall_rating'],
             'potential' => $player['potential'], 'form' => $player['form'], 'morale' => $player['morale'], 'fatigue' => $player['fatigue']]
        );
        $attributeParams = ['save_id' => $saveId, 'player_id' => $playerId];
        foreach (PlayerFactory::ATTRIBUTES as $attribute) {
            $attributeParams[$attribute] = $data['attributes'][$attribute];
        }
        $columns = implode(', ', PlayerFactory::ATTRIBUTES);
        $placeholders = ':' . implode(', :', PlayerFactory::ATTRIBUTES);
        $this->database->insert(
            "INSERT INTO player_attributes (save_id, player_id, $columns) VALUES (:save_id, :player_id, $placeholders)",
            $attributeParams
        );
        if ($withContract && $clubId !== null) {
            $this->database->insert(
                'INSERT INTO contracts (save_id, player_id, club_id, starts_on, ends_on, salary, squad_role)
                 VALUES (:save_id, :player_id, :club_id, :starts_on, :ends_on, :salary, :role)',
                ['save_id' => $saveId, 'player_id' => $playerId, 'club_id' => $clubId, 'starts_on' => $seasonStart->format('Y-m-d'),
                 'ends_on' => $seasonStart->modify('+' . random_int(1, 5) . ' years')->format('Y-m-d'), 'salary' => $data['salary'],
                 'role' => $data['player']['overall_rating'] >= 72 ? 'key_player' : 'regular']
            );
        }
        return $playerId;
    }

    private function seedFreeAgents(int $saveId, DateTimeImmutable $seasonStart): void
    {
        $players = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/database/data/players.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($players as $custom) {
            $data = $this->playerFactory->make((string) $custom['position'], (int) $custom['age'], (int) $custom['overall'], (int) $custom['potential'], (string) $custom['nationality'], $seasonStart, (string) $custom['first_name'], (string) $custom['last_name']);
            $this->insertPlayer($saveId, null, $data, $seasonStart, false);
        }
    }

    private function seedFixtures(int $saveId, int $seasonId, array $leagueIds, array $clubIds, int $year): void
    {
        $firstMatch = new DateTimeImmutable($year . '-08-01');
        if ((int) $firstMatch->format('N') !== 6) {
            $firstMatch = $firstMatch->modify('next saturday');
        }
        foreach ($clubIds as $level => $ids) {
            foreach ($this->fixtureGenerator->generate($ids, $firstMatch) as $fixture) {
                $this->database->insert(
                    'INSERT INTO fixtures (save_id, season_id, league_id, round_number, leg, matchday, scheduled_at, home_club_id, away_club_id)
                     VALUES (:save_id, :season_id, :league_id, :round_number, :leg, :matchday, :scheduled_at, :home, :away)',
                    ['save_id' => $saveId, 'season_id' => $seasonId, 'league_id' => $leagueIds[$level], 'round_number' => $fixture['matchday'],
                     'leg' => $fixture['leg'], 'matchday' => $fixture['matchday'], 'scheduled_at' => $fixture['date']->format('Y-m-d H:i:s'),
                     'home' => $fixture['home'], 'away' => $fixture['away']]
                );
            }
        }
    }

}

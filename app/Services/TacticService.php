<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Models\PlayerRepository;
use App\Security\ValidationException;

final class TacticService
{
    public const FORMATIONS = [
        '4-4-2' => ['GK','DL','DC','DC','DR','ML','MC','MC','MR','ST','ST'],
        '4-3-3' => ['GK','DL','DC','DC','DR','MDC','MC','MC','ML','MR','ST'],
        '4-2-3-1' => ['GK','DL','DC','DC','DR','MDC','MDC','ML','AMC','MR','ST'],
        '3-5-2' => ['GK','DC','DC','DC','WBL','MC','MC','MC','WBR','ST','ST'],
        '5-3-2' => ['GK','WBL','DC','DC','DC','WBR','MC','MC','MC','ST','ST'],
        '4-1-4-1' => ['GK','DL','DC','DC','DR','MDC','ML','MC','MC','MR','ST'],
    ];

    public const FORMATION_COORDINATES = [
        '4-4-2' => [[50,88],[15,66],[38,68],[62,68],[85,66],[14,42],[38,46],[62,46],[86,42],[35,18],[65,18]],
        '4-3-3' => [[50,88],[15,66],[38,68],[62,68],[85,66],[50,54],[32,40],[68,40],[16,20],[84,20],[50,14]],
        '4-2-3-1' => [[50,88],[15,66],[38,68],[62,68],[85,66],[36,52],[64,52],[16,31],[50,34],[84,31],[50,13]],
        '3-5-2' => [[50,88],[27,67],[50,70],[73,67],[10,42],[32,47],[50,40],[68,47],[90,42],[35,16],[65,16]],
        '5-3-2' => [[50,88],[10,52],[27,68],[50,71],[73,68],[90,52],[30,42],[50,47],[70,42],[35,16],[65,16]],
        '4-1-4-1' => [[50,88],[15,66],[38,68],[62,68],[85,66],[50,53],[14,35],[36,39],[64,39],[86,35],[50,13]],
    ];

    public function __construct(private readonly Database $database, private readonly PlayerRepository $players)
    {
    }

    public function createDefault(int $saveId, int $clubId, array $players): int
    {
        $players = $this->filterAvailable($players, $this->currentDate($saveId));
        $lineup = $this->bestLineup($players, '4-3-3');
        $used = array_column($lineup, 'player_id');
        $substitutes = [];
        foreach ($players as $player) {
            if (!in_array((int) $player['id'], $used, true)) {
                $substitutes[] = ['player_id' => (int) $player['id'], 'instruction' => 'normal'];
            }
            if (count($substitutes) === 7) {
                break;
            }
        }
        $captain = $lineup[0]['player_id'];
        $penalty = $lineup[0]['player_id'];
        $freeKick = $lineup[0]['player_id'];
        $best = 0;
        foreach ($lineup as $entry) {
            foreach ($players as $player) {
                if ((int) $player['id'] !== $entry['player_id']) {
                    continue;
                }
                if ((int) $player['overall_rating'] > $best) {
                    $captain = $entry['player_id'];
                }
                if ((int) ($player['shooting'] ?? 0) > $best) {
                    $penalty = $entry['player_id'];
                }
                if ((int) ($player['passing'] ?? 0) > $best) {
                    $freeKick = $entry['player_id'];
                }
                $best = max($best, (int) $player['overall_rating'], (int) ($player['shooting'] ?? 0), (int) ($player['passing'] ?? 0));
            }
        }
        return $this->save($saveId, $clubId, [
            'formation' => '4-3-3', 'mentality' => 'balanced', 'playing_style' => 'mixed', 'pressing' => 'medium',
            'defensive_line' => 'normal', 'tempo' => 'normal', 'width' => 'normal', 'lineup' => $lineup,
            'substitutes' => $substitutes, 'captain_player_id' => $captain, 'penalty_taker_id' => $penalty,
            'free_kick_taker_id' => $freeKick,
        ]);
    }

    public function getDefault(int $saveId, int $clubId): array
    {
        $tactic = $this->database->selectOne(
            'SELECT * FROM tactics WHERE save_id = :save_id AND club_id = :club_id AND is_default = 1 ORDER BY id DESC LIMIT 1',
            ['save_id' => $saveId, 'club_id' => $clubId]
        );
        if ($tactic === null) {
            $players = $this->players->forClub($saveId, $clubId);
            $id = $this->createDefault($saveId, $clubId, $players);
            $tactic = $this->database->selectOne('SELECT * FROM tactics WHERE id = :id AND save_id = :save_id', ['id' => $id, 'save_id' => $saveId]);
        }
        $decoded = $this->decode($tactic);
        $available = $this->availablePlayers($saveId, $clubId);
        $availableIds = array_map(static fn ($p) => (int)$p['id'], $available);
        $selectedIds = array_map(static fn ($e) => (int)$e['player_id'], array_merge($decoded['lineup'], $decoded['substitutes']));
        if (array_diff($selectedIds, $availableIds) !== []) {
            $this->createDefault($saveId, $clubId, $available);
            $tactic = $this->database->selectOne('SELECT * FROM tactics WHERE save_id=:save_id AND club_id=:club_id AND is_default=1 ORDER BY id DESC LIMIT 1', ['save_id'=>$saveId,'club_id'=>$clubId]);
            $decoded = $this->decode($tactic);
        }
        return $decoded;
    }

    public function forFixture(int $saveId, int $clubId, int $fixtureId): array
    {
        $tactic = $this->database->selectOne(
            'SELECT * FROM tactics WHERE save_id = :save_id AND club_id = :club_id AND fixture_id = :fixture_id LIMIT 1',
            ['save_id' => $saveId, 'club_id' => $clubId, 'fixture_id' => $fixtureId]
        );
        return $tactic !== null ? $this->decode($tactic) : $this->getDefault($saveId, $clubId);
    }

    /** Conserve les onze joueurs tout en appliquant réellement les postes de la nouvelle structure. */
    public function remapLineup(array $lineup, string $formation): array
    {
        if (!isset(self::FORMATIONS[$formation], self::FORMATION_COORDINATES[$formation])) {
            throw new ValidationException(['formation' => 'Formation inconnue.']);
        }
        if (count($lineup) !== 11) {
            throw new ValidationException(['lineup' => 'Exactement 11 titulaires sont requis.']);
        }

        $remapped = [];
        foreach (array_values($lineup) as $index => $entry) {
            $entry['slot'] = $index;
            $entry['position'] = self::FORMATIONS[$formation][$index];
            [$entry['x'], $entry['y']] = self::FORMATION_COORDINATES[$formation][$index];
            $remapped[] = $entry;
        }
        return $remapped;
    }

    public function save(int $saveId, int $clubId, array $input, ?int $fixtureId = null): int
    {
        $formation = (string) ($input['formation'] ?? '4-3-3');
        if (!isset(self::FORMATIONS[$formation])) {
            throw new ValidationException(['formation' => 'Formation inconnue.']);
        }
        $players = $this->availablePlayers($saveId, $clubId);
        $allowed = array_fill_keys(array_map(static fn ($p) => (int) $p['id'], $players), true);
        $lineup = $input['lineup'] ?? [];
        if (!is_array($lineup) || count($lineup) !== 11) {
            throw new ValidationException(['lineup' => 'Exactement 11 titulaires sont requis.']);
        }
        $seen = [];
        $cleanLineup = [];
        foreach ($lineup as $index => $entry) {
            $playerId = (int) ($entry['player_id'] ?? 0);
            if (!isset($allowed[$playerId]) || isset($seen[$playerId])) {
                throw new ValidationException(['lineup' => 'Titulaire invalide ou dupliqué.']);
            }
            $seen[$playerId] = true;
            $position = (string) ($entry['position'] ?? self::FORMATIONS[$formation][$index]);
            if (!preg_match('/^[A-Z]{1,4}$/', $position)) {
                throw new ValidationException(['lineup' => 'Poste invalide.']);
            }
            $instruction = (string) ($entry['instruction'] ?? 'normal');
            if (!in_array($instruction, ['normal','attack','stay_back'], true)) {
                $instruction = 'normal';
            }
            $role = (string) ($entry['role'] ?? 'support');
            if (!in_array($role, ['support','attack','defend','playmaker','target'], true)) {
                $role = 'support';
            }
            $cleanLineup[] = [
                'player_id' => $playerId, 'slot' => (int) $index, 'position' => $position,
                'x' => max(0, min(100, (float) ($entry['x'] ?? 50))), 'y' => max(0, min(100, (float) ($entry['y'] ?? 50))),
                'instruction' => $instruction, 'role' => $role,
            ];
        }
        $cleanSubs = [];
        foreach ((array) ($input['substitutes'] ?? []) as $entry) {
            $playerId = is_array($entry) ? (int) ($entry['player_id'] ?? 0) : (int) $entry;
            if (isset($allowed[$playerId]) && !isset($seen[$playerId])) {
                $seen[$playerId] = true;
                $cleanSubs[] = ['player_id' => $playerId];
            }
            if (count($cleanSubs) === 7) {
                break;
            }
        }
        $options = [
            'mentality' => ['very_defensive','defensive','balanced','attacking','very_attacking'],
            'playing_style' => ['possession','direct','counter','wings','long_ball','mixed'],
            'pressing' => ['low','medium','high'], 'defensive_line' => ['deep','normal','high'],
            'tempo' => ['slow','normal','fast'], 'width' => ['narrow','normal','wide'],
        ];
        $defaults = ['mentality' => 'balanced', 'playing_style' => 'mixed', 'pressing' => 'medium', 'defensive_line' => 'normal', 'tempo' => 'normal', 'width' => 'normal'];
        $values = [];
        foreach ($options as $key => $allowedValues) {
            $values[$key] = in_array(($input[$key] ?? ''), $allowedValues, true) ? $input[$key] : $defaults[$key];
        }
        $special = [];
        foreach (['captain_player_id','penalty_taker_id','free_kick_taker_id'] as $key) {
            $candidate = (int) ($input[$key] ?? 0);
            $special[$key] = isset($allowed[$candidate]) ? $candidate : $cleanLineup[0]['player_id'];
        }
        $existing = $this->database->selectOne(
            'SELECT id FROM tactics WHERE save_id = :save_id AND club_id = :club_id AND ' . ($fixtureId === null ? 'is_default = 1' : 'fixture_id = :fixture_id') . ' ORDER BY id DESC LIMIT 1',
            array_filter(['save_id' => $saveId, 'club_id' => $clubId, 'fixture_id' => $fixtureId], static fn ($v) => $v !== null)
        );
        $params = array_merge($values, $special, [
            'save_id' => $saveId, 'club_id' => $clubId, 'fixture_id' => $fixtureId, 'formation' => $formation,
            'lineup' => json_encode($cleanLineup, JSON_THROW_ON_ERROR), 'substitutes' => json_encode($cleanSubs, JSON_THROW_ON_ERROR),
            'individual_instructions' => json_encode([], JSON_THROW_ON_ERROR),
        ]);
        if ($existing !== null) {
            $this->database->execute(
                'UPDATE tactics SET formation = :formation, mentality = :mentality, playing_style = :playing_style,
                 pressing = :pressing, defensive_line = :defensive_line, tempo = :tempo, width = :width,
                 lineup = :lineup, substitutes = :substitutes, individual_instructions = :individual_instructions,
                 captain_player_id = :captain_player_id, penalty_taker_id = :penalty_taker_id, free_kick_taker_id = :free_kick_taker_id
                 WHERE id = :id AND save_id = :save_id',
                array_merge($params, ['id' => (int) $existing['id']])
            );
            return (int) $existing['id'];
        }
        return (int) $this->database->insert(
            'INSERT INTO tactics (save_id, club_id, fixture_id, name, formation, mentality, playing_style, pressing,
             defensive_line, tempo, width, lineup, substitutes, individual_instructions, captain_player_id,
             penalty_taker_id, free_kick_taker_id, is_default)
             VALUES (:save_id, :club_id, :fixture_id, :name, :formation, :mentality, :playing_style, :pressing,
             :defensive_line, :tempo, :width, :lineup, :substitutes, :individual_instructions, :captain_player_id,
             :penalty_taker_id, :free_kick_taker_id, :is_default)',
            array_merge($params, ['name' => $fixtureId === null ? 'Tactique principale' : 'Tactique de match', 'is_default' => $fixtureId === null ? 1 : 0])
        );
    }

    /** Joueurs aptes à être sélectionnés à la date de la sauvegarde. */
    public function availablePlayers(int $saveId, int $clubId): array
    {
        return $this->filterAvailable($this->players->forClub($saveId, $clubId), $this->currentDate($saveId));
    }

    private function currentDate(int $saveId): string
    {
        $save = $this->database->selectOne('SELECT `current_date` FROM saves WHERE id=:save_id', ['save_id'=>$saveId]);
        return (string)($save['current_date'] ?? date('Y-m-d'));
    }

    private function filterAvailable(array $players, string $date): array
    {
        return array_values(array_filter($players, static fn ($p) => empty($p['injury_until']) || (string)$p['injury_until'] < $date));
    }

    private function bestLineup(array $players, string $formation): array
    {
        $available = $players;
        $lineup = [];
        foreach (self::FORMATIONS[$formation] as $slot => $position) {
            $bestIndex = null;
            $bestScore = -999;
            foreach ($available as $index => $player) {
                $secondary = json_decode((string) ($player['secondary_positions'] ?? '[]'), true) ?: [];
                $score = (int) $player['overall_rating'] + ($player['primary_position'] === $position ? 25 : (in_array($position, $secondary, true) ? 12 : 0));
                if ($position === 'GK' && $player['primary_position'] !== 'GK') {
                    $score -= 100;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $index;
                }
            }
            if ($bestIndex === null) {
                throw new ValidationException(['lineup' => 'Effectif insuffisant pour définir onze titulaires.']);
            }
            $player = $available[$bestIndex];
            $lineup[] = ['player_id' => (int) $player['id'], 'slot' => $slot, 'position' => $position, 'x' => 50, 'y' => 50, 'instruction' => 'normal', 'role' => 'support'];
            unset($available[$bestIndex]);
        }
        return $lineup;
    }

    private function decode(?array $tactic): array
    {
        if ($tactic === null) {
            throw new \RuntimeException('Tactique introuvable.');
        }
        foreach (['lineup','substitutes','individual_instructions'] as $key) {
            $tactic[$key] = json_decode((string) ($tactic[$key] ?? '[]'), true) ?: [];
        }
        foreach ($tactic['lineup'] as &$entry) {
            $entry['instruction'] = $entry['instruction'] ?? 'normal';
            $entry['role'] = $entry['role'] ?? 'support';
        }
        unset($entry);
        return $tactic;
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EventDispatcherInterface;
use App\Contracts\MatchEngineInterface;
use App\Core\Config;
use App\Database\Database;
use App\Models\PlayerRepository;
use RuntimeException;

final class MatchService
{
    public function __construct(
        private readonly Database $database,
        private readonly MatchEngineInterface $engine,
        private readonly PlayerRepository $players,
        private readonly TacticService $tactics,
        private readonly LeagueService $league,
        private readonly MoraleService $morale,
        private readonly MessageService $messages,
        private readonly EventDispatcherInterface $events,
        private readonly Config $config
    ) {
    }

    public function fixture(int $saveId, int $fixtureId): array
    {
        $fixture = $this->database->selectOne(
            'SELECT f.*, h.name AS home_name, h.short_name AS home_short, h.slug AS home_slug, h.primary_color AS home_color,
             a.name AS away_name, a.short_name AS away_short, a.slug AS away_slug, a.primary_color AS away_color
             FROM fixtures f JOIN clubs h ON h.id=f.home_club_id JOIN clubs a ON a.id=f.away_club_id
             WHERE f.save_id=:save_id AND f.id=:fixture_id',
            ['save_id' => $saveId, 'fixture_id' => $fixtureId]
        );
        if ($fixture === null) {
            throw new RuntimeException('Match introuvable.');
        }
        return $fixture;
    }

    public function fixtureForUser(GameContext $context, int $fixtureId): array
    {
        $fixture = $this->fixture($context->saveId(), $fixtureId);
        $this->assertUserClub($context, $fixture);
        return $fixture;
    }

    public function start(GameContext $context, int $fixtureId): array
    {
        $this->fixtureForUser($context, $fixtureId);
        return $this->database->transaction(function () use ($context, $fixtureId): array {
            $fixture = $this->lockedFixture($context->saveId(), $fixtureId);
            $state = $this->decodeState($fixture);
            if ($fixture['status'] === 'finished') {
                throw new RuntimeException('Le match est déjà terminé.');
            }
            if ($state === null) {
                $this->assertMatchDate($context, $fixture);
                $state = $this->initializeState($fixture, $context);
                $this->persist($context->saveId(), $fixture, $state);
            }
            return $state;
        });
    }

    public function step(GameContext $context, int $fixtureId): array
    {
        $this->fixtureForUser($context, $fixtureId);
        return $this->database->transaction(function () use ($context, $fixtureId): array {
            $fixture = $this->lockedFixture($context->saveId(), $fixtureId);
            if ($fixture['status'] === 'finished') {
                throw new RuntimeException('Le match est terminé.');
            }
            if ($fixture['status'] === 'halftime') {
                return $this->decodeState($fixture);
            }
            $state = $this->decodeState($fixture);
            if ($state === null) {
                $this->assertMatchDate($context, $fixture);
                $state = $this->initializeState($fixture, $context);
            }
            $state = $this->engine->advance($state, false);
            $this->insertEvents($context->saveId(), $fixture, $state['new_events']);
            $this->persist($context->saveId(), $fixture, $state);
            if ($state['status'] === 'finished') {
                $this->finalize($context->saveId(), $fixture, $state, $context);
            }
            return $state;
        });
    }

    public function resume(GameContext $context, int $fixtureId): array
    {
        $this->fixtureForUser($context, $fixtureId);
        return $this->database->transaction(function () use ($context, $fixtureId): array {
            $fixture = $this->lockedFixture($context->saveId(), $fixtureId);
            $state = $this->decodeState($fixture);
            if ($state === null || $fixture['status'] !== 'halftime') {
                throw new RuntimeException('Le match n’est pas à la mi-temps.');
            }
            $state['status'] = 'live';
            $state['new_events'] = [];
            $this->persist($context->saveId(), $fixture, $state);
            return $state;
        });
    }

    public function substitute(GameContext $context, int $fixtureId, int $out, int $in): array
    {
        $this->fixtureForUser($context, $fixtureId);
        return $this->database->transaction(function () use ($context, $fixtureId, $out, $in): array {
            $fixture = $this->lockedFixture($context->saveId(), $fixtureId);
            $state = $this->decodeState($fixture);
            if ($state === null || $state['status'] === 'finished') {
                throw new RuntimeException('Remplacement impossible.');
            }
            $state['new_events'] = [];
            $state = $this->engine->substitute($state, $this->userSide($context, $fixture), $out, $in);
            $this->insertEvents($context->saveId(), $fixture, $state['new_events']);
            $this->persist($context->saveId(), $fixture, $state);
            return $state;
        });
    }

    public function changeTactic(GameContext $context, int $fixtureId, array $input): array
    {
        $this->fixtureForUser($context, $fixtureId);
        return $this->database->transaction(function () use ($context, $fixtureId, $input): array {
            $fixture = $this->lockedFixture($context->saveId(), $fixtureId);
            $state = $this->decodeState($fixture);
            if ($state === null || $state['status'] === 'finished') {
                throw new RuntimeException('Modification impossible.');
            }
            $side = $this->userSide($context, $fixture);
            $input['lineup'] = $state['sides'][$side]['tactic']['lineup'];
            $input['substitutes'] = $state['sides'][$side]['tactic']['substitutes'];
            $this->tactics->save($context->saveId(), $context->clubId(), $input, $fixtureId);
            $tactic = $this->tactics->forFixture($context->saveId(), $context->clubId(), $fixtureId);
            $state['new_events'] = [];
            $state = $this->engine->changeTactic($state, $side, $tactic);
            $this->insertEvents($context->saveId(), $fixture, $state['new_events']);
            $this->persist($context->saveId(), $fixture, $state);
            return $state;
        });
    }

    public function simulate(int $saveId, int $fixtureId, ?GameContext $context = null): array
    {
        return $this->database->transaction(function () use ($saveId, $fixtureId, $context): array {
            $fixture = $this->lockedFixture($saveId, $fixtureId);
            $state = $this->decodeState($fixture);
            if ($fixture['status'] === 'finished') {
                if ($state !== null) {
                    return $state;
                }
                throw new RuntimeException('État du match terminé introuvable.');
            }
            if ($state === null) {
                $state = $this->initializeState($fixture, $context);
            }
            if ($state['status'] === 'halftime') {
                $state['status'] = 'live';
            }
            while ($state['status'] !== 'finished') {
                $state = $this->engine->advance($state, true);
                $this->insertEvents($saveId, $fixture, $state['new_events']);
            }
            $this->persist($saveId, $fixture, $state);
            $this->finalize($saveId, $fixture, $state, $context);
            return $state;
        });
    }

    public function events(int $saveId, int $fixtureId): array
    {
        return $this->database->select(
            'SELECT * FROM match_events WHERE save_id=:save_id AND fixture_id=:fixture_id ORDER BY minute, added_time, id',
            ['save_id' => $saveId, 'fixture_id' => $fixtureId]
        );
    }

    private function initializeState(array $fixture, ?GameContext $context): array
    {
        $saveId = (int) $fixture['save_id'];
        $home = $this->players->forClub($saveId, (int) $fixture['home_club_id']);
        $away = $this->players->forClub($saveId, (int) $fixture['away_club_id']);
        $homeTactic = $this->tactics->forFixture($saveId, (int) $fixture['home_club_id'], (int) $fixture['id']);
        $awayTactic = $this->tactics->forFixture($saveId, (int) $fixture['away_club_id'], (int) $fixture['id']);

        if ($context !== null) {
            $difficulty = (string) $context->save()['difficulty'];
            $managedClubId = $context->clubId();
        } else {
            $save = $this->database->selectOne('SELECT difficulty, club_id FROM saves WHERE id=:save_id', ['save_id' => $saveId]);
            $difficulty = (string) ($save['difficulty'] ?? 'normal');
            $managedClubId = (int) ($save['club_id'] ?? 0);
        }
        $aiStrength = (float) $this->config->get('game.difficulties.' . $difficulty . '.ai_strength', 1.0);
        $state = $this->engine->initialize($home, $away, $homeTactic, $awayTactic, [
            'home_ai_strength' => (int) $fixture['home_club_id'] === $managedClubId ? 1.0 : $aiStrength,
            'away_ai_strength' => (int) $fixture['away_club_id'] === $managedClubId ? 1.0 : $aiStrength,
        ]);
        $state['save_id'] = $saveId;
        $state['fixture_id'] = (int) $fixture['id'];
        $state['home_club_id'] = (int) $fixture['home_club_id'];
        $state['away_club_id'] = (int) $fixture['away_club_id'];
        $state['new_events'] = [[
            'minute' => 0, 'type' => 'info', 'side' => null,
            'description' => 'Coup d’envoi entre ' . $fixture['home_name'] . ' et ' . $fixture['away_name'] . '.',
            'player_id' => null, 'related_player_id' => null, 'data' => [],
        ]];
        $this->insertEvents($saveId, $fixture, $state['new_events']);
        return $state;
    }

    private function persist(int $saveId, array $fixture, array $state): void
    {
        $this->database->execute(
            'UPDATE fixtures SET status=:status, current_minute=:minute, home_score=:home_score, away_score=:away_score,
             statistics=:statistics, match_state=:match_state, started_at=COALESCE(started_at,NOW()), finished_at=:finished_at
             WHERE save_id=:save_id AND id=:fixture_id',
            [
                'status' => $state['status'], 'minute' => (int) $state['minute'],
                'home_score' => (int) $state['home_score'], 'away_score' => (int) $state['away_score'],
                'statistics' => json_encode(['home' => $state['stats']['home'], 'away' => $state['stats']['away']], JSON_THROW_ON_ERROR),
                'match_state' => json_encode($state, JSON_THROW_ON_ERROR),
                'finished_at' => $state['status'] === 'finished' ? date('Y-m-d H:i:s') : null,
                'save_id' => $saveId, 'fixture_id' => (int) $fixture['id'],
            ]
        );
    }

    private function finalize(int $saveId, array $fixture, array $state, ?GameContext $context): void
    {
        $completed = array_merge($fixture, ['home_score' => $state['home_score'], 'away_score' => $state['away_score'], 'status' => 'finished']);
        $this->league->recordResult($saveId, $completed);
        $this->morale->afterMatch($saveId, $fixture, $state);

        $injuries = $this->database->select(
            'SELECT e.player_id, e.data, f.scheduled_at FROM match_events e JOIN fixtures f ON f.id=e.fixture_id
             WHERE e.save_id=:save_id AND e.fixture_id=:fixture_id AND e.type=\'injury\'',
            ['save_id' => $saveId, 'fixture_id' => (int) $fixture['id']]
        );
        foreach ($injuries as $injury) {
            $data = json_decode((string) $injury['data'], true) ?: [];
            $this->database->execute(
                'UPDATE players SET injury_type=\'Blessure musculaire\', injury_until=:injury_until
                 WHERE save_id=:save_id AND id=:player_id',
                ['injury_until' => (new \DateTimeImmutable(date('Y-m-d', strtotime((string)$injury['scheduled_at']))))->modify('+'.(int)($data['days'] ?? 7).' days')->format('Y-m-d'), 'save_id' => $saveId, 'player_id' => (int) $injury['player_id']]
            );
        }

        if ($context !== null && in_array($context->clubId(), [(int) $fixture['home_club_id'], (int) $fixture['away_club_id']], true)) {
            $home = (int) $fixture['home_club_id'] === $context->clubId();
            $own = $home ? $state['home_score'] : $state['away_score'];
            $other = $home ? $state['away_score'] : $state['home_score'];
            $result = $own > $other ? 'Victoire' : ($own === $other ? 'Match nul' : 'Défaite');
            $this->messages->send(
                $saveId, $context->userId(), $context->clubId(), $result . ' ' . $own . '-' . $other,
                'Le match contre ' . ($home ? $fixture['away_name'] : $fixture['home_name']) . ' est terminé. Consultez les statistiques et le rapport.',
                'result', '/saves/' . $saveId . '/matches/' . (int) $fixture['id']
            );
        }
        $this->events->dispatch('match.finished', ['save_id' => $saveId, 'fixture_id' => (int) $fixture['id'], 'fixture' => $completed, 'state' => $state]);
    }

    private function insertEvents(int $saveId, array $fixture, array $events): void
    {
        foreach ($events as $event) {
            $clubId = isset($event['side']) ? (int) $fixture[$event['side'] . '_club_id'] : null;
            $this->database->insert(
                'INSERT INTO match_events (save_id,fixture_id,club_id,player_id,related_player_id,minute,type,description,data)
                 VALUES (:save_id,:fixture_id,:club_id,:player_id,:related_id,:minute,:type,:description,:data)',
                ['save_id' => $saveId, 'fixture_id' => (int) $fixture['id'], 'club_id' => $clubId,
                 'player_id' => $event['player_id'], 'related_id' => $event['related_player_id'], 'minute' => (int) $event['minute'],
                 'type' => $event['type'], 'description' => $event['description'], 'data' => json_encode($event['data'], JSON_THROW_ON_ERROR)]
            );
        }
    }

    private function lockedFixture(int $saveId, int $fixtureId): array
    {
        $fixture = $this->database->selectOne(
            'SELECT f.*, h.name AS home_name, a.name AS away_name FROM fixtures f
             JOIN clubs h ON h.id=f.home_club_id JOIN clubs a ON a.id=f.away_club_id
             WHERE f.save_id=:save_id AND f.id=:fixture_id FOR UPDATE',
            ['save_id' => $saveId, 'fixture_id' => $fixtureId]
        );
        if ($fixture === null) {
            throw new RuntimeException('Match introuvable.');
        }
        return $fixture;
    }

    private function decodeState(array $fixture): ?array
    {
        return $fixture['match_state'] ? json_decode((string) $fixture['match_state'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    private function assertUserClub(GameContext $context, array $fixture): void
    {
        if (!in_array($context->clubId(), [(int) $fixture['home_club_id'], (int) $fixture['away_club_id']], true)) {
            throw new RuntimeException('Ce match ne concerne pas votre club.');
        }
    }

    private function assertMatchDate(GameContext $context, array $fixture): void
    {
        if (date('Y-m-d', strtotime((string) $fixture['scheduled_at'])) > (string) $context->save()['current_date']) {
            throw new RuntimeException('Ce match n’est pas encore jouable. Avancez dans le calendrier.');
        }
    }

    private function userSide(GameContext $context, array $fixture): string
    {
        return (int) $fixture['home_club_id'] === $context->clubId() ? 'home' : 'away';
    }
}

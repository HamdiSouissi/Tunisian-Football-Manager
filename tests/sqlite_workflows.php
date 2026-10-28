<?php

declare(strict_types=1);

use App\Core\Application;
use App\Database\Database;
use App\Models\PlayerRepository;
use App\Services\GameContext;
use App\Services\GameLoopService;
use App\Services\PlayerFactory;
use App\Services\TacticService;
use App\Session\Session;

/** Adaptateur de test : SQLite ignore FOR UPDATE et traduit la forme MySQL du classement. */
final class WorkflowSQLitePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = (string)preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
        $query = str_replace(
            ['RIGHT(CONCAT(form, :home_form), 5)', 'RIGHT(CONCAT(form, :away_form), 5)'],
            ['SUBSTR(form || :home_form, -5)', 'SUBSTR(form || :away_form, -5)'],
            $query
        );
        return parent::prepare($query, $options);
    }
}

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $app->boot();
    $pdo = new WorkflowSQLitePDO('sqlite::memory:', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ]);
    $pdo->sqliteCreateFunction('GREATEST', static fn (...$values) => max($values));
    $pdo->sqliteCreateFunction('LEAST', static fn (...$values) => min($values));
    $pdo->sqliteCreateFunction('IF', static fn ($condition, $yes, $no) => $condition ? $yes : $no);
    $pdo->sqliteCreateFunction('NOW', static fn () => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('RAND', static fn () => random_int(0, 1000000) / 1000000);

    // Schéma minimal : seules les tables des parcours exécutés sont nécessaires.
    $schema = [
        'CREATE TABLE saves (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, name TEXT, manager_name TEXT, difficulty TEXT, current_date TEXT NOT NULL, club_id INTEGER, current_season_id INTEGER, status TEXT NOT NULL)',
        'CREATE TABLE leagues (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, name TEXT, short_name TEXT, slug TEXT, level INTEGER, team_count INTEGER)',
        'CREATE TABLE clubs (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, league_id INTEGER, name TEXT, short_name TEXT, slug TEXT, city TEXT, stadium_name TEXT, stadium_capacity INTEGER, primary_color TEXT, secondary_color TEXT, reputation INTEGER, balance REAL, transfer_budget REAL, wage_budget REAL, board_confidence INTEGER)',
        'CREATE TABLE seasons (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, name TEXT, starts_on TEXT, ends_on TEXT, status TEXT)',
        'CREATE TABLE fixtures (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, season_id INTEGER, league_id INTEGER, round_number INTEGER DEFAULT 1, leg TEXT DEFAULT \'first\', matchday INTEGER DEFAULT 1, scheduled_at TEXT, status TEXT DEFAULT \'scheduled\', home_club_id INTEGER, away_club_id INTEGER, current_minute INTEGER DEFAULT 0, home_score INTEGER, away_score INTEGER, attendance INTEGER, statistics TEXT, match_state TEXT, started_at TEXT, finished_at TEXT)',
        'CREATE TABLE match_events (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, fixture_id INTEGER NOT NULL, club_id INTEGER, player_id INTEGER, related_player_id INTEGER, minute INTEGER, added_time INTEGER DEFAULT 0, type TEXT, description TEXT, data TEXT)',
        'CREATE TABLE standings (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, season_id INTEGER NOT NULL, league_id INTEGER NOT NULL, club_id INTEGER NOT NULL, position INTEGER DEFAULT 0, played INTEGER DEFAULT 0, wins INTEGER DEFAULT 0, draws INTEGER DEFAULT 0, losses INTEGER DEFAULT 0, goals_for INTEGER DEFAULT 0, goals_against INTEGER DEFAULT 0, goal_difference INTEGER DEFAULT 0, points INTEGER DEFAULT 0, form TEXT DEFAULT \'\')',
        'CREATE TABLE players (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, club_id INTEGER, first_name TEXT, last_name TEXT, birth_date TEXT, nationality TEXT, primary_position TEXT, secondary_positions TEXT, preferred_foot TEXT, height_cm INTEGER, weight_kg INTEGER, market_value REAL, overall_rating INTEGER, potential INTEGER, experience INTEGER DEFAULT 0, form INTEGER, morale INTEGER, fatigue INTEGER, injury_type TEXT, injury_until TEXT, is_retired INTEGER DEFAULT 0)',
        'CREATE TABLE contracts (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, player_id INTEGER, club_id INTEGER, starts_on TEXT, ends_on TEXT, salary REAL, squad_role TEXT, release_clause REAL, signing_bonus REAL, status TEXT DEFAULT \'active\')',
        'CREATE TABLE staff (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, club_id INTEGER, quality INTEGER, wage REAL)',
        'CREATE TABLE training_plans (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, club_id INTEGER, week_start TEXT, focus TEXT, intensity TEXT, applied_at TEXT)',
        'CREATE TABLE training_plan_players (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, training_plan_id INTEGER, player_id INTEGER, target_attribute TEXT, intensity TEXT)',
        'CREATE TABLE training_reports (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, training_plan_id INTEGER, club_id INTEGER, week_start TEXT, summary TEXT)',
        'CREATE TABLE training_report_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, training_report_id INTEGER, player_id INTEGER, attribute TEXT, previous_value INTEGER, new_value INTEGER, fatigue_delta INTEGER, morale_delta INTEGER, injury_occurred INTEGER, notes TEXT)',
        'CREATE TABLE finances (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, club_id INTEGER, season_id INTEGER, occurred_on TEXT, direction TEXT, category TEXT, description TEXT, amount REAL, reference_type TEXT, reference_id INTEGER)',
        'CREATE TABLE transfer_offers (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, parent_offer_id INTEGER, player_id INTEGER, from_club_id INTEGER, to_club_id INTEGER, initiated_by TEXT, offer_type TEXT, amount REAL, wage REAL, contract_years INTEGER, signing_bonus REAL, loan_duration_months INTEGER, loan_wage_share REAL, buy_option REAL, status TEXT DEFAULT \'pending\', message TEXT, expires_at TEXT, responded_at TEXT)',
        'CREATE TABLE transfers (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, season_id INTEGER, player_id INTEGER, from_club_id INTEGER, to_club_id INTEGER, type TEXT, fee REAL, status TEXT, transfer_date TEXT, completed_at TEXT, details TEXT)',
        'CREATE TABLE loans (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, status TEXT, ends_on TEXT)',
        'CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, user_id INTEGER, club_id INTEGER, subject TEXT, body TEXT, category TEXT, action_url TEXT, is_read INTEGER DEFAULT 0)',
        'CREATE TABLE tactics (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, club_id INTEGER NOT NULL, fixture_id INTEGER, name TEXT, formation TEXT, mentality TEXT, playing_style TEXT, pressing TEXT, defensive_line TEXT, tempo TEXT, width TEXT, lineup TEXT, substitutes TEXT, individual_instructions TEXT, captain_player_id INTEGER, penalty_taker_id INTEGER, free_kick_taker_id INTEGER, is_default INTEGER)',
    ];
    foreach ($schema as $statement) {
        $pdo->exec($statement);
    }
    $attributeColumns = implode(',', array_map(static fn (string $attribute): string => $attribute . ' INTEGER NOT NULL DEFAULT 65', PlayerFactory::ATTRIBUTES));
    $pdo->exec('CREATE TABLE player_attributes (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, player_id INTEGER NOT NULL, ' . $attributeColumns . ')');

    $pdo->exec("INSERT INTO saves VALUES (1,1,'Test','Coach','normal','2026-07-01',1,1,'active')");
    $pdo->exec("INSERT INTO leagues VALUES (1,1,'Ligue 1','L1','ligue-1',1,16)");
    $pdo->exec("INSERT INTO clubs VALUES (1,1,1,'Club Test','CT','club-test','Tunis','Stade',20000,'#15966f','#ffffff',70,1000000,500000,50000,60)");
    $pdo->exec("INSERT INTO seasons VALUES (1,1,'2026-2027','2026-07-01','2027-05-31','active')");
    $pdo->exec('INSERT INTO staff VALUES (1,1,1,70,500)');

    $playerIds = [];
    $playerInsert = $pdo->prepare('INSERT INTO players (id,save_id,club_id,first_name,last_name,birth_date,nationality,primary_position,secondary_positions,preferred_foot,height_cm,weight_kg,market_value,overall_rating,potential,form,morale,fatigue,injury_type,injury_until,is_retired) VALUES (?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0)');
    $attributeInsert = $pdo->prepare('INSERT INTO player_attributes (save_id,player_id) VALUES (1,?)');
    $contractInsert = $pdo->prepare("INSERT INTO contracts (save_id,player_id,club_id,starts_on,ends_on,salary,squad_role,status) VALUES (1,?,?,'2026-07-01','2029-06-30',1000,'regular','active')");
    foreach (TacticService::FORMATIONS['4-3-3'] as $index => $position) {
        $id = $index + 1;
        $playerIds[] = $id;
        $playerInsert->execute([$id, 1, 'Joueur', (string)$id, '2000-01-01', 'Tunisia', $position, '[]', 'right', 180, 75, 100000, 65, 75, 60, 70, 0, null, null]);
        $attributeInsert->execute([$id]);
        $contractInsert->execute([$id, 1]);
    }

    $database = new Database([]);
    $pdoProperty = new ReflectionProperty(Database::class, 'pdo');
    $pdoProperty->setAccessible(true);
    $pdoProperty->setValue($database, $pdo);
    $app->container()->instance(Database::class, $database);
    $session = new Session([]);
    $session->set('user_id', 1);
    $app->container()->instance(Session::class, $session);
    $app->config()->set('game.transfers.ai_daily_transfer_chance', 0.0);
    $app->config()->set('game.transfers.ai_daily_user_offer_chance', 0.0);
    $app->config()->set('game.training.intensity.medium.injury', 0.0);

    /** @var GameContext $context */
    $context = $app->make(GameContext::class);
    $context->load(1);
    $repository = new PlayerRepository($database);
    $tactics = new TacticService($database, $repository);
    $lineup = $tactics->remapLineup(array_map(static fn (int $id): array => ['player_id' => $id], $playerIds), '4-3-3');
    $input = [
        'formation' => '4-3-3', 'mentality' => 'balanced', 'playing_style' => 'mixed',
        'pressing' => 'medium', 'defensive_line' => 'normal', 'tempo' => 'normal', 'width' => 'normal',
        'lineup' => $lineup, 'substitutes' => [], 'captain_player_id' => $playerIds[0],
        'penalty_taker_id' => $playerIds[0], 'free_kick_taker_id' => $playerIds[0],
    ];
    $tacticId = $tactics->save(1, 1, $input);
    $assert($tacticId > 0, 'La création initiale de la tactique a échoué.');

    /** @var GameLoopService $gameLoop */
    $gameLoop = $app->make(GameLoopService::class);
    $pdo->exec('UPDATE players SET fatigue=CASE id WHEN 1 THEN 0 WHEN 2 THEN 5 WHEN 3 THEN 7 WHEN 4 THEN 8 ELSE fatigue END WHERE id IN (1,2,3,4)');
    $oneDay = $gameLoop->advance($context, 1);
    $assert($oneDay['days'] === 1 && $context->save()['current_date'] === '2026-07-02', 'Le bouton +1 jour ne produit pas la date attendue.');
    $fatigueValues = $pdo->query('SELECT fatigue FROM players WHERE id IN (1,2,3,4) ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    $assert([0, 0, 0, 1] === array_map('intval', $fatigueValues), 'La récupération doit borner la fatigue à zéro sans dépassement négatif.');

    $sevenDays = $gameLoop->advance($context, 7);
    $assert($sevenDays['days'] === 7 && $context->save()['current_date'] === '2026-07-09', 'Continuer ne produit pas les sept jours attendus.');
    $assert($sevenDays['training'] === 1, 'Continuer doit appliquer exactement une semaine d’entraînement.');
    $reportCount = (int)$pdo->query('SELECT COUNT(*) FROM training_reports')->fetchColumn();
    $entryCount = (int)$pdo->query('SELECT COUNT(*) FROM training_report_entries')->fetchColumn();
    $financeCount = (int)$pdo->query('SELECT COUNT(*) FROM finances')->fetchColumn();
    $assert($reportCount === 1 && $entryCount === 11, 'Le rapport hebdomadaire ou ses onze lignes est incomplet.');
    $assert($financeCount >= 2, 'Les écritures hebdomadaires de salaires et sponsors sont absentes.');

    // Le deuxième enregistrement emprunte obligatoirement le UPDATE du stack trace utilisateur.
    $input['formation'] = '4-4-2';
    $input['lineup'] = $tactics->remapLineup($input['lineup'], '4-4-2');
    $updatedId = $tactics->save(1, 1, $input);
    $storedFormation = (string)$pdo->query('SELECT formation FROM tactics WHERE id=' . $tacticId)->fetchColumn();
    $assert($updatedId === $tacticId && $storedFormation === '4-4-2', 'La mise à jour de la tactique ne persiste pas la formation.');

    // Une indisponibilité peut déclencher ce même UPDATE lors de la simulation quotidienne.
    $playerInsert->execute([12, 1, 'Joueur', '12', '2001-01-01', 'Tunisia', 'MC', '[]', 'right', 180, 75, 100000, 65, 75, 60, 70, 0, null, null]);
    $attributeInsert->execute([12]);
    $contractInsert->execute([12, 1]);
    $pdo->exec("UPDATE players SET injury_type='Blessure de test', injury_until='2026-12-31' WHERE id=2");
    $rebuiltTactic = $tactics->getDefault(1, 1);
    $rebuiltIds = array_map(static fn (array $entry): int => (int)$entry['player_id'], $rebuiltTactic['lineup']);
    $assert(count($rebuiltIds) === 11 && !in_array(2, $rebuiltIds, true) && in_array(12, $rebuiltIds, true), 'La reconstruction automatique du onze après blessure est incorrecte.');

    // Les deux actions IA sont forcées pour couvrir le mercato déclenché par +1 jour.
    $pdo->exec("INSERT INTO clubs VALUES (2,1,1,'Club Adverse','CA','club-adverse','Tunis','Stade B',15000,'#2974b8','#ffffff',60,800000,500000,40000,60)");
    $playerInsert->execute([13, 2, 'Joueur', '13', '2002-01-01', 'Tunisia', 'ST', '[]', 'right', 180, 75, 100000, 65, 75, 60, 70, 0, null, null]);
    $attributeInsert->execute([13]);
    $contractInsert->execute([13, 2]);
    $app->config()->set('game.transfers.ai_daily_transfer_chance', 1.0);
    $app->config()->set('game.transfers.ai_daily_user_offer_chance', 1.0);
    $aiDay = $gameLoop->advance($context, 1);
    $assert($aiDay['days'] === 1 && $aiDay['transfers'] === 2 && $context->save()['current_date'] === '2026-07-10', 'Les actions IA du mercato interrompent l’avancement quotidien.');
    $assert((int)$pdo->query('SELECT COUNT(*) FROM transfers')->fetchColumn() === 1, 'Le transfert IA n’a pas été historisé.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM transfer_offers WHERE status='pending'")->fetchColumn() >= 1, 'L’offre IA destinée au joueur n’a pas été créée.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM finances WHERE category IN ('transfer_purchase','transfer_sale')")->fetchColumn() === 2, 'Les écritures financières du transfert IA sont incomplètes.');

    // Le jour du match, la boucle s'arrête et seul le bouton déclenche la rencontre du club dirigé.
    $app->config()->set('game.transfers.ai_daily_transfer_chance', 0.0);
    $app->config()->set('game.transfers.ai_daily_user_offer_chance', 0.0);
    foreach (TacticService::FORMATIONS['4-3-3'] as $index => $position) {
        $id = 100 + $index;
        $playerInsert->execute([$id, 2, 'Adverse', (string)$id, '2000-01-01', 'Tunisia', $position, '[]', 'right', 180, 75, 100000, 65, 75, 60, 70, 0, null, null]);
        $attributeInsert->execute([$id]);
        $contractInsert->execute([$id, 2]);
    }
    $pdo->exec('INSERT INTO standings (save_id,season_id,league_id,club_id) VALUES (1,1,1,1),(1,1,1,2)');
    $pdo->exec("INSERT INTO fixtures (id,save_id,season_id,league_id,matchday,scheduled_at,home_club_id,away_club_id) VALUES (1,1,1,1,1,'2026-07-11 15:00:00',1,2)");
    $matchService = $app->make(App\Services\MatchService::class);
    $futureRejected = false;
    try {
        $matchService->simulateForUser($context, 1);
    } catch (RuntimeException $exception) {
        $futureRejected = str_contains($exception->getMessage(), 'pas encore jouable');
    }
    $assert($futureRejected && $pdo->query('SELECT status FROM fixtures WHERE id=1')->fetchColumn() === 'scheduled', 'Un match futur ne doit pas être simulable.');

    $calendarService = $app->make(App\Services\CalendarService::class);
    $matchDay = $calendarService->advanceUntil($context, '2026-07-15');
    $assert($matchDay['days'] === 1 && $matchDay['awaiting_match'] === 1 && $matchDay['reached'] === false && $context->save()['current_date'] === '2026-07-11', 'Simuler jusqu’à une date doit s’arrêter le jour du match du club.');
    $assert($pdo->query('SELECT status FROM fixtures WHERE id=1')->fetchColumn() === 'scheduled', 'Le match du club ne doit plus être simulé sans action du manager.');
    $advanceBlocked = false;
    try {
        $gameLoop->advance($context, 1);
    } catch (App\Security\ValidationException $exception) {
        $advanceBlocked = true;
    }
    $assert($advanceBlocked && $context->save()['current_date'] === '2026-07-11', 'Continuer doit être bloqué tant que le match du jour est en attente.');

    $save = $context->save();
    $club = $context->club();
    $next = $matchService->fixture($context->saveId(), 1);
    $standing = null;
    $objective = null;
    $topPlayers = [];
    $recent = [];
    $messages = [];
    ob_start();
    try {
        include base_path('modules/Game/views/dashboard.php');
        $dashboardHtml = (string)ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
    $assert(str_contains($dashboardHtml, 'action="/saves/1/matches/1/simulate"') && str_contains($dashboardHtml, '>Jouer le match</button>'), 'Le dashboard doit afficher le bouton Jouer le match le jour de la rencontre.');

    $matchController = $app->make(Modules\Match\Controllers\MatchController::class);
    $app->config()->set('app.debug', true);
    $startResponse = $matchController->start(new App\Core\Request('POST', '/saves/1/matches/1/start', [], [], [], []), '1', '1');
    $legacyState = json_decode((string)$pdo->query('SELECT match_state FROM fixtures WHERE id=1')->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    unset($legacyState['spatial'], $legacyState['weather'], $legacyState['player_ratings'], $legacyState['player_minutes'], $legacyState['experience_gained']);
    $legacyState['stats'] = ['home'=>['possession'=>0,'shots'=>0], 'away'=>['possession'=>0,'shots'=>0]];
    $pdo->prepare('UPDATE fixtures SET match_state=:state WHERE id=1')->execute(['state'=>json_encode($legacyState, JSON_THROW_ON_ERROR)]);
    $stepResponse = $matchController->step(new App\Core\Request('POST', '/saves/1/matches/1/step', [], [], [], []), '1', '1');
    $stepPayload = json_decode($stepResponse->content(), true, 512, JSON_THROW_ON_ERROR);
    $assert($startResponse->status() === 302 && $stepResponse->status() === 200 && $stepPayload['minute'] === 1 && count($stepPayload['visual']['frames']) === 10, 'Le démarrage puis la lecture de la première minute doivent fonctionner : ' . $stepResponse->content());
    $secondStepResponse = $matchController->step(new App\Core\Request('POST', '/saves/1/matches/1/step', [], [], [], []), '1', '1');
    $secondStepPayload = json_decode($secondStepResponse->content(), true, 512, JSON_THROW_ON_ERROR);
    $assert($secondStepResponse->status() === 200 && $secondStepPayload['minute'] === 2 && count($secondStepPayload['visual']['frames']) === 10, 'La lecture doit continuer après persistence de l’état hydraté : ' . $secondStepResponse->content());
    $response = $matchController->simulate(new App\Core\Request('POST', '/saves/1/matches/1/simulate', [], [], [], []), '1', '1');
    $assert($response->status() === 302, 'Le bouton Jouer le match doit rediriger vers le rapport : ' . $response->content());
    $finished = $pdo->query('SELECT * FROM fixtures WHERE id=1')->fetch(PDO::FETCH_ASSOC);
    $finishedState = json_decode((string)$finished['match_state'], true, 512, JSON_THROW_ON_ERROR);
    $goalCount = (int)$pdo->query("SELECT COUNT(*) FROM match_events WHERE fixture_id=1 AND type IN ('goal','penalty_goal')")->fetchColumn();
    $played = array_map('intval', $pdo->query('SELECT played FROM standings WHERE club_id IN (1,2) ORDER BY club_id')->fetchAll(PDO::FETCH_COLUMN));
    $assert($finished['status'] === 'finished' && (int)$finished['current_minute'] === 90 + (int)$finishedState['added_time']['second'] && $finishedState['status'] === 'finished', 'La simulation doit produire un match terminé après le temps additionnel.');
    $assert(isset($finishedState['man_of_the_match']['id']) && count($finishedState['player_ratings']['home']) === 11 && count($finishedState['player_ratings']['away']) === 11, 'Le rapport doit conserver les notes et l’homme du match.');
    $assert(max($finishedState['player_minutes']['home']) === (int)$finished['current_minute'] && array_sum($finishedState['experience_gained']['home']) > 0, 'Les minutes et les gains d’expérience doivent être calculés.');
    $assert((int)$pdo->query('SELECT COALESCE(SUM(experience),0) FROM players WHERE club_id=1')->fetchColumn() === array_sum($finishedState['experience_gained']['home']), 'L’expérience persistée doit correspondre aux gains du rapport.');
    $lastFrame = $finishedState['spatial']['timeline'][array_key_last($finishedState['spatial']['timeline'])] ?? null;
    $assert(isset($finishedState['weather']['type']) && $lastFrame !== null && count($lastFrame['players']['home']) >= 10 && count($lastFrame['players']['away']) >= 10, 'La simulation doit persister la météo et les positions des joueurs non expulsés.');
    $assert($goalCount === (int)$finished['home_score'] + (int)$finished['away_score'], 'Le score simulé doit correspondre aux événements de but.');
    $assert($played === [1, 1], 'Les deux clubs doivent avoir exactement un match de plus au classement.');
    $assert((int)$finished['attendance'] > 0 && (int)$pdo->query("SELECT COUNT(*) FROM finances WHERE category='ticketing' AND reference_id=1")->fetchColumn() === 1, 'La billetterie du match simulé est absente.');
    $assert((int)$pdo->query("SELECT COUNT(*) FROM messages WHERE category='result' AND action_url='/saves/1/matches/1'")->fetchColumn() === 1, 'Le message de résultat est absent.');
    $report = $matchController->show(new App\Core\Request('GET', '/saves/1/matches/1', [], [], [], []), '1', '1');
    $reportHtml = $report->content();
    $assert($report->status() === 200 && str_contains($reportHtml, 'id="match-pitch"') && str_contains($reportHtml, 'id="match-initial-data"') && str_contains($reportHtml, 'Homme du match'), 'Le rapport doit exposer le terrain et ses données initiales. HTTP ' . $report->status() . ' : ' . substr(strip_tags($reportHtml), 0, 500));
    $leagueController = $app->make(Modules\League\Controllers\LeagueController::class);
    $calendarResponse = $leagueController->calendar(new App\Core\Request('GET', '/saves/1/calendar', ['view'=>'month','date'=>'2026-07-11'], [], [], []));
    $calendarHtml = $calendarResponse->content();
    $assert($calendarResponse->status() === 200 && str_contains($calendarHtml, 'calendar-month') && str_contains($calendarHtml, 'Forme récente') && str_contains($calendarHtml, 'Simuler jusqu’à cette date'), 'Le calendrier mensuel et sa préparation doivent être rendus. HTTP ' . $calendarResponse->status());

    $eventCount = (int)$pdo->query('SELECT COUNT(*) FROM match_events WHERE fixture_id=1')->fetchColumn();
    $matchService->simulateForUser($context, 1);
    $assert((int)$pdo->query('SELECT COUNT(*) FROM match_events WHERE fixture_id=1')->fetchColumn() === $eventCount, 'Une seconde simulation ne doit pas dupliquer les événements.');
    $assert(array_map('intval', $pdo->query('SELECT played FROM standings WHERE club_id IN (1,2) ORDER BY club_id')->fetchAll(PDO::FETCH_COLUMN)) === [1, 1], 'Une seconde simulation ne doit pas finaliser deux fois le classement.');

    $pdo->exec("INSERT INTO clubs (id,save_id,league_id,name) VALUES (3,1,1,'Club Tiers')");
    $pdo->exec("INSERT INTO fixtures (id,save_id,season_id,league_id,matchday,scheduled_at,home_club_id,away_club_id) VALUES (3,1,1,1,1,'2026-07-11 15:00:00',2,3)");
    $foreignRejected = false;
    try {
        $matchService->simulateForUser($context, 3);
    } catch (RuntimeException $exception) {
        $foreignRejected = str_contains($exception->getMessage(), 'ne concerne pas votre club');
    }
    $assert($foreignRejected, 'Le bouton ne doit pas permettre de simuler le match d’un autre club.');
    $pdo->exec('DELETE FROM fixtures WHERE id=3');

    $afterMatch = $gameLoop->advance($context, 1);
    $assert($afterMatch['days'] === 1 && $context->save()['current_date'] === '2026-07-12', 'Le calendrier doit pouvoir reprendre après la simulation.');

    $session->invalidate();
    echo "Workflows SQLite PDO (+1 jour, Continuer, tactique, mercato IA, jour de match) : OK\n";
} catch (Throwable $exception) {
    echo 'ÉCHEC : ' . $exception::class . ' : ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    exit(1);
}

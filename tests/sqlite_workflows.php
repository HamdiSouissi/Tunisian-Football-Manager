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

/** SQLite ignore le verrou MySQL dans ce test ; les autres requêtes restent préparées par PDO. */
final class WorkflowSQLitePDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $query = (string)preg_replace('/\s+FOR\s+UPDATE\b/i', '', $query);
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
        'CREATE TABLE fixtures (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, season_id INTEGER, scheduled_at TEXT, status TEXT, home_club_id INTEGER, away_club_id INTEGER)',
        'CREATE TABLE players (id INTEGER PRIMARY KEY, save_id INTEGER NOT NULL, club_id INTEGER, first_name TEXT, last_name TEXT, birth_date TEXT, nationality TEXT, primary_position TEXT, secondary_positions TEXT, preferred_foot TEXT, height_cm INTEGER, weight_kg INTEGER, market_value REAL, overall_rating INTEGER, potential INTEGER, form INTEGER, morale INTEGER, fatigue INTEGER, injury_type TEXT, injury_until TEXT, is_retired INTEGER DEFAULT 0)',
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
        'CREATE TABLE messages (id INTEGER PRIMARY KEY AUTOINCREMENT, save_id INTEGER NOT NULL, user_id INTEGER, club_id INTEGER, subject TEXT, body TEXT, category TEXT, action_url TEXT)',
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

    $session->invalidate();
    echo "Workflows SQLite PDO (+1 jour, Continuer, tactique, mercato IA) : OK\n";
} catch (Throwable $exception) {
    echo 'ÉCHEC : ' . $exception::class . ' : ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n";
    exit(1);
}

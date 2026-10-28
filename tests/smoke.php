<?php

declare(strict_types=1);

use App\Contracts\EventDispatcherInterface;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Database\Database;
use App\Database\Migrator;
use App\Events\EventDispatcher;
use App\Middleware\CsrfMiddleware;
use App\Security\Csrf;
use App\Session\Session;

/** @var Application $app */
$app = require dirname(__DIR__) . '/bootstrap/app.php';

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s (attendu : %s ; obtenu : %s)',
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
};

try {
    $app->boot();

    // Le conteneur conserve bien l'instance de l'interface partagée.
    $assertSame(
        $app->events(),
        $app->make(EventDispatcherInterface::class),
        'L’interface du dispatcher doit résoudre le service partagé.'
    );

    // Les hooks génériques et les priorités sont déterministes.
    $dispatcher = new EventDispatcher();
    $calls = [];
    $dispatcher->listen('match.*', static function () use (&$calls): void {
        $calls[] = 'low';
    }, -10);
    $dispatcher->listen('match.finished', static function () use (&$calls): void {
        $calls[] = 'high';
    }, 10);
    $dispatcher->dispatch('match.finished', ['fixture_id' => 42]);
    $assertSame(['high', 'low'], $calls, 'Ordre de dispatch incorrect.');

    // Tous les services et contrôleurs principaux sont autowirés sans connexion SQL.
    $resolvable = [
        App\Services\GameLoopService::class,
        App\Services\SeasonService::class,
        App\Services\TrainingService::class,
        App\Services\TransferService::class,
        App\Services\FinanceService::class,
        Modules\Auth\Controllers\AuthController::class,
        Modules\Auth\Controllers\SaveController::class,
        Modules\Game\Controllers\DashboardController::class,
        Modules\Squad\Controllers\SquadController::class,
        Modules\Tactics\Controllers\TacticsController::class,
        Modules\Match\Controllers\MatchController::class,
        Modules\League\Controllers\LeagueController::class,
        Modules\Training\Controllers\TrainingController::class,
        Modules\Transfers\Controllers\TransferController::class,
        Modules\Finances\Controllers\FinancesController::class,
    ];
    foreach ($resolvable as $class) {
        $assertSame(true, $app->make($class) instanceof $class, 'Autowiring impossible pour ' . $class . '.');
    }
    $assertSame(true, $app->make(App\Contracts\MatchEngineInterface::class) instanceof App\Engine\TacticalMatchEngine, 'Binding du moteur de match incorrect.');
    $assertSame(true, $app->make(App\Contracts\AIManagerInterface::class) instanceof App\Services\ClubAIManager, 'Binding de l’IA incorrect.');

    // Chaque migration est découpée sans casser les littéraux (dont les couleurs #fff).
    $migrator = new Migrator(new Database([]), '', []);
    $splitter = new ReflectionMethod(Migrator::class, 'splitStatements');
    $splitter->setAccessible(true);
    $statementCount = 0;
    foreach (glob(base_path('database/migrations/*.sql')) ?: [] as $migrationFile) {
        $statements = $splitter->invoke($migrator, (string) file_get_contents($migrationFile));
        $statementCount += count($statements);
    }
    $assertSame(27, $statementCount, 'Nombre inattendu d’instructions de migration.');

    // CURRENT_DATE est un mot-clé MySQL : l'identifiant doit rester échappé dans chaque requête.
    $quotedDateSql = [
        'app/Services/WorldSeeder.php' => 'difficulty, `current_date`)',
        'app/Services/TacticService.php' => 'SELECT `current_date` FROM saves',
        'app/Services/SeasonService.php' => 'current_season_id=:season_id,`current_date`=:date',
        'app/Services/GameLoopService.php' => 'UPDATE saves SET `current_date`=:date',
    ];
    foreach ($quotedDateSql as $file => $expectedSql) {
        $source = (string) file_get_contents(base_path($file));
        $assertSame(true, str_contains($source, $expectedSql), 'Identifiant current_date non échappé dans ' . $file . '.');
    }

    // L'avancement utilise une opération DateTimeImmutable disponible en PHP 8.1.
    $loopReflection = new ReflectionClass(App\Services\GameLoopService::class);
    $nextDate = $loopReflection->getMethod('nextDate');
    $nextDate->setAccessible(true);
    $loop = $loopReflection->newInstanceWithoutConstructor();
    $assertSame('2026-08-01', $nextDate->invoke($loop, '2026-07-31'), 'Le passage au mois suivant est incorrect.');
    $assertSame('2028-02-29', $nextDate->invoke($loop, '2028-02-28'), 'Le passage par une année bissextile est incorrect.');

    // PDO natif n'autorise pas deux occurrences d'un même paramètre nommé dans une requête.
    $literalPattern = <<<'REGEX'
~'(?:\\.|[^'\\])*'~s
REGEX;
    foreach (['app', 'modules'] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            preg_match_all($literalPattern, $source, $literals);
            foreach ($literals[0] as $literal) {
                if (preg_match('/\b(?:SELECT|UPDATE|INSERT|DELETE)\b/i', $literal) !== 1) {
                    continue;
                }
                preg_match_all('/(?<!:):([a-zA-Z_][a-zA-Z0-9_]*)/', $literal, $parameters);
                foreach (array_count_values($parameters[1]) as $parameter => $occurrences) {
                    $assertSame(false, $occurrences > 1, 'Paramètre PDO dupliqué :' . $parameter . ' dans ' . $file->getPathname() . '.');
                }
            }
        }
    }

    // Les portraits sont déterministes, distincts et ne réinjectent aucune donnée HTML.
    $portraitGenerator = new App\Services\PlayerPortrait();
    $portraitPlayer = ['id' => 42, 'first_name' => 'Aymen', 'last_name' => 'Ben Salem', 'birth_date' => '2002-04-10', 'primary_position' => 'ST'];
    $portrait = $portraitGenerator->render($portraitPlayer, 'sm');
    $assertSame($portrait, $portraitGenerator->render($portraitPlayer, 'sm'), 'Le portrait doit rester stable entre deux rendus.');
    $otherPortrait = $portraitGenerator->render([...$portraitPlayer, 'id' => 43], 'sm');
    $assertSame(false, $portrait === $otherPortrait, 'Deux joueurs doivent recevoir des portraits distincts.');
    $assertSame(true, str_contains($portrait, '<svg') && str_contains($portrait, 'role="img"'), 'Le portrait SVG accessible est incomplet.');
    $unsafePortrait = $portraitGenerator->render([...$portraitPlayer, 'first_name' => '<Test>', 'club_color' => '\"><script>alert(1)</script>']);
    $assertSame(true, str_contains($unsafePortrait, '&lt;Test&gt;') && !str_contains($unsafePortrait, '<script>'), 'Le portrait doit échapper les données du joueur.');

    // La page Finances doit fonctionner avant la première écriture comptable.
    $renderFinances = static function (array $monthly): string {
        $club = ['balance' => 1000, 'wage_budget' => 500, 'transfer_budget' => 800, 'board_confidence' => 60];
        $totals = ['income' => 0, 'expense' => 0];
        $weeklyWages = 0;
        $ledger = [];
        ob_start();
        try {
            include base_path('modules/Finances/views/index.php');
            return (string)ob_get_clean();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
    };
    $emptyFinances = $renderFinances([]);
    $assertSame(true, str_contains($emptyFinances, 'Les premières opérations apparaîtront'), 'La vue Finances vide doit afficher son état initial.');
    $filledFinances = $renderFinances([['month' => '2026-07', 'net' => -250], ['month' => '2026-08', 'net' => 500]]);
    $assertSame(true, str_contains($filledFinances, '2026-08') && str_contains($filledFinances, '+500 TND'), 'Le graphique Finances doit rendre les flux mensuels.');

    // La fiche enrichie est rendue avec un joueur complet, sans accès SQL.
    $context = new class { public function saveId(): int { return 1; } };
    $save = ['current_date' => '2026-07-01'];
    $player = array_merge([
        'id' => 42, 'first_name' => 'Aymen', 'last_name' => 'Ben Salem', 'birth_date' => '2002-04-10',
        'nationality' => 'Tunisia', 'primary_position' => 'ST', 'secondary_positions' => '["AMC"]',
        'preferred_foot' => 'left', 'height_cm' => 182, 'weight_kg' => 76, 'overall_rating' => 68,
        'potential' => 75, 'market_value' => 250000, 'contract_salary' => 2200,
        'contract_starts' => '2025-07-01', 'contract_ends' => '2028-06-30', 'squad_role' => 'key_player',
        'release_clause' => 500000, 'form' => 65, 'morale' => 75, 'fatigue' => 12,
        'injury_type' => null, 'injury_until' => null, 'club_name' => 'Espérance de Tunis',
        'club_slug' => 'esperance-de-tunis', 'club_color' => '#d43f55', 'club_secondary_color' => '#f5f7f6',
    ], array_fill_keys(App\Services\PlayerFactory::ATTRIBUTES, 65));
    $attributes = App\Services\PlayerFactory::ATTRIBUTES;
    $radar = ['Technique' => 65, 'Mental' => 65, 'Physique' => 65, 'Défense' => 65, 'Attaque' => 65, 'Gardien' => 65];
    $history = [];
    $progress = [];
    $career = ['goals' => 3, 'assists' => 2, 'yellow_cards' => 1, 'red_cards' => 0, 'injuries' => 0];
    ob_start();
    try {
        include base_path('modules/Squad/views/show.php');
        $playerPage = (string)ob_get_clean();
    } catch (Throwable $exception) {
        ob_end_clean();
        throw $exception;
    }
    $assertSame(true, str_contains($playerPage, '<svg') && str_contains($playerPage, 'Statistiques de carrière'), 'La fiche joueur enrichie doit être rendue avec son portrait.');
    $assertSame(true, str_contains($playerPage, 'Joueur cadre') && str_contains($playerPage, 'Passes décisives'), 'Le contrat et les statistiques du joueur sont incomplets.');

    // Le middleware accepte un jeton d'en-tête et rejette une modification invalide.
    $sessionPath = sys_get_temp_dir() . '/tfm-smoke-' . getmypid();
    $session = new Session([
        ...(array) config('session'),
        'name' => 'tfm_smoke',
        'save_path' => $sessionPath,
    ]);
    $session->start();
    $csrf = new Csrf($session);
    $middleware = new CsrfMiddleware($csrf);
    $next = static fn (Request $request): Response => Response::json(['ok' => true]);
    $valid = $middleware->handle(
        new Request('POST', '/', [], [], [], ['x-csrf-token' => $csrf->token()]),
        $next
    );
    $assertSame(200, $valid->status(), 'Un jeton CSRF valide doit être accepté.');
    $invalid = $middleware->handle(
        new Request('POST', '/', [], ['_token' => 'invalide'], [], []),
        $next
    );
    $assertSame(419, $invalid->status(), 'Un jeton CSRF invalide doit être refusé.');
    $session->invalidate();
    @rmdir($sessionPath);

    // Le module System est découvert et sa route de santé répond sans PDO.
    $router = new Router($app->container());
    $router->addGlobalMiddleware(CsrfMiddleware::class);
    $app->modules()->loadRoutes($router);
    $health = $router->dispatch(new Request('GET', '/health', [], [], [], []));
    $assertSame(200, $health->status(), 'La route /health doit répondre 200.');
    $payload = json_decode($health->content(), true, 512, JSON_THROW_ON_ERROR);
    $assertSame('ok', $payload['status'] ?? null, 'Réponse de santé invalide.');

    // Les pages invitées et les protections HTTP ne nécessitent aucune base.
    $login = $router->dispatch(new Request('GET', '/login', [], [], [], []));
    $assertSame(200, $login->status(), 'La page de connexion doit être rendue.');
    $assertSame(true, str_contains($login->content(), 'Connexion'), 'Le formulaire de connexion est incomplet.');
    $assertSame(200, $router->dispatch(new Request('GET', '/register', [], [], [], []))->status(), 'La page d’inscription doit être rendue.');
    $assertSame(302, $router->dispatch(new Request('GET', '/', [], [], [], []))->status(), 'La racine doit rediriger un invité.');
    $assertSame(302, $router->dispatch(new Request('GET', '/saves', [], [], [], []))->status(), 'Les parties doivent être protégées.');
    $assertSame(419, $router->dispatch(new Request('POST', '/login', [], [], [], []))->status(), 'Une connexion sans CSRF doit être bloquée avant PDO.');

    // Le catalogue correspond bien aux deux divisions tunisiennes attendues.
    $catalog = new App\Services\ClubCatalog(base_path('database/data'));
    $leagues = $catalog->leagues();
    $assertSame(2, count($leagues), 'Le catalogue doit contenir deux divisions.');
    $assertSame(16, count($leagues[0]['clubs']), 'La Ligue 1 doit contenir 16 clubs.');
    $assertSame(16, count($leagues[1]['clubs']), 'La Ligue 2 doit contenir 16 clubs.');
    $assertSame(32, count($catalog->clubs()), 'Le monde doit contenir 32 clubs.');
    foreach ($catalog->clubs() as $club) {
        $logo = 'public/assets/img/clubs/' . $club['slug'] . '.png';
        $assertSame(true, is_file(base_path($logo)), 'Blason local manquant pour ' . $club['slug'] . '.');
        $assertSame('/assets/img/clubs/' . $club['slug'] . '.png', club_logo_url($club['slug']), 'URL de blason invalide.');
    }
    $assertSame('/assets/img/clubs/fallback.svg', club_logo_url('../club-inconnu'), 'Le fallback doit rejeter les slugs invalides.');
    $assertSame('/assets/img/clubs/fallback.svg', club_logo_url('club-inconnu'), 'Le fallback doit couvrir un asset absent.');
    $freeAgents = json_decode((string)file_get_contents(base_path('database/data/players.json')), true, 512, JSON_THROW_ON_ERROR);
    $assertSame(12, count($freeAgents), 'Le seed doit contenir douze joueurs libres.');

    // Vérifie les paramètres de route et leurs contraintes.
    $router->get('/test/{id}', static fn (Request $request, string $id): Response =>
        Response::json(['id' => $id])
    )->where('id', '[0-9]+');
    $route = $router->dispatch(new Request('GET', '/test/17', [], [], [], []));
    $assertSame('{"id":"17"}', $route->content(), 'Paramètre de route mal transmis.');
    $assertSame(
        404,
        $router->dispatch(new Request('GET', '/test/abc', [], [], [], []))->status(),
        'La contrainte numérique doit rejeter ce chemin.'
    );

    echo "Smoke tests : OK\n";
} catch (Throwable $exception) {
    $message = $exception->getMessage() . PHP_EOL;
    if (defined('STDERR')) {
        fwrite(STDERR, $message);
    } else {
        echo $message;
    }
    exit(1);
}

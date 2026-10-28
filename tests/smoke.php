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

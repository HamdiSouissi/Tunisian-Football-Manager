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

    // Chaque migration est découpée sans casser les littéraux (dont les couleurs #fff).
    $migrator = new Migrator(new Database([]), '', []);
    $splitter = new ReflectionMethod(Migrator::class, 'splitStatements');
    $splitter->setAccessible(true);
    $statementCount = 0;
    foreach (glob(base_path('database/migrations/*.sql')) ?: [] as $migrationFile) {
        $statements = $splitter->invoke($migrator, (string) file_get_contents($migrationFile));
        $statementCount += count($statements);
    }
    $assertSame(26, $statementCount, 'Nombre inattendu d’instructions de migration.');

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
    $app->modules()->loadRoutes($router);
    $health = $router->dispatch(new Request('GET', '/health', [], [], [], []));
    $assertSame(200, $health->status(), 'La route /health doit répondre 200.');
    $payload = json_decode($health->content(), true, 512, JSON_THROW_ON_ERROR);
    $assertSame('ok', $payload['status'] ?? null, 'Réponse de santé invalide.');

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
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}

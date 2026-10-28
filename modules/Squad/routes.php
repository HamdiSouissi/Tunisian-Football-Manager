<?php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Squad\Controllers\SquadController;
$secure = [AuthMiddleware::class, SaveMiddleware::class];
return static function (Router $router, Application $app) use ($secure): void {
    $router->get('/saves/{save}/squad', [SquadController::class, 'index'])->where('save','[0-9]+')->middleware(...$secure);
    $router->get('/saves/{save}/players/{player}', [SquadController::class, 'show'])->where('save','[0-9]+')->where('player','[0-9]+')->middleware(...$secure);
};

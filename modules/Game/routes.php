<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Game\Controllers\DashboardController;

$secure = [AuthMiddleware::class, SaveMiddleware::class];
return static function (Router $router, Application $app) use ($secure): void {
    $router->get('/saves/{save}', [DashboardController::class, 'index'])->where('save', '[0-9]+')->middleware(...$secure);
    $router->post('/saves/{save}/continue', [DashboardController::class, 'advance'])->where('save', '[0-9]+')->middleware(...$secure);
    $router->get('/saves/{save}/inbox', [DashboardController::class, 'inbox'])->where('save', '[0-9]+')->middleware(...$secure);
    $router->post('/saves/{save}/messages/{message}/read', [DashboardController::class, 'readMessage'])->where('save', '[0-9]+')->where('message', '[0-9]+')->middleware(...$secure);
    $router->get('/saves/{save}/club', [DashboardController::class, 'club'])->where('save', '[0-9]+')->middleware(...$secure);
    $router->get('/saves/{save}/staff', [DashboardController::class, 'staff'])->where('save', '[0-9]+')->middleware(...$secure);
};

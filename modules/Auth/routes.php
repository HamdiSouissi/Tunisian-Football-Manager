<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use Modules\Auth\Controllers\AuthController;
use Modules\Auth\Controllers\SaveController;

return static function (Router $router, Application $app): void {
    $router->get('/', [AuthController::class, 'home']);
    $router->get('/login', [AuthController::class, 'loginForm']);
    $router->post('/login', [AuthController::class, 'login']);
    $router->get('/register', [AuthController::class, 'registerForm']);
    $router->post('/register', [AuthController::class, 'register']);
    $router->post('/logout', [AuthController::class, 'logout'])->middleware(AuthMiddleware::class);
    $router->get('/saves', [SaveController::class, 'index'])->middleware(AuthMiddleware::class);
    $router->get('/saves/create', [SaveController::class, 'create'])->middleware(AuthMiddleware::class);
    $router->post('/saves', [SaveController::class, 'store'])->middleware(AuthMiddleware::class);
    $router->post('/saves/{save}/archive', [SaveController::class, 'archive'])->where('save', '[0-9]+')->middleware(AuthMiddleware::class);
};

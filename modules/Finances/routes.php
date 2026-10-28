<?php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Finances\Controllers\FinancesController;
$secure=[AuthMiddleware::class,SaveMiddleware::class];
return static function(Router $router,Application $app) use ($secure): void {$router->get('/saves/{save}/finances',[FinancesController::class,'index'])->where('save','[0-9]+')->middleware(...$secure);};

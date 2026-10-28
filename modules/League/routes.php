<?php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\League\Controllers\LeagueController;
$secure=[AuthMiddleware::class,SaveMiddleware::class];
return static function(Router $router,Application $app) use ($secure): void {
 $router->get('/saves/{save}/calendar',[LeagueController::class,'calendar'])->where('save','[0-9]+')->middleware(...$secure);
 $router->get('/saves/{save}/standings',[LeagueController::class,'standings'])->where('save','[0-9]+')->middleware(...$secure);
};

<?php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Tactics\Controllers\TacticsController;
$secure=[AuthMiddleware::class,SaveMiddleware::class];
return static function(Router $router,Application $app) use ($secure): void {
 $router->get('/saves/{save}/tactics',[TacticsController::class,'index'])->where('save','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/tactics',[TacticsController::class,'save'])->where('save','[0-9]+')->middleware(...$secure);
};

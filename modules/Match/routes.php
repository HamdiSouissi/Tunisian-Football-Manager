<?php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Match\Controllers\MatchController;
$secure=[AuthMiddleware::class,SaveMiddleware::class];
return static function(Router $router,Application $app) use ($secure): void {
 $router->get('/saves/{save}/matches/{fixture}',[MatchController::class,'show'])->where('save','[0-9]+')->where('fixture','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/matches/{fixture}/start',[MatchController::class,'start'])->where('save','[0-9]+')->where('fixture','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/matches/{fixture}/step',[MatchController::class,'step'])->where('save','[0-9]+')->where('fixture','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/matches/{fixture}/resume',[MatchController::class,'resume'])->where('save','[0-9]+')->where('fixture','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/matches/{fixture}/substitution',[MatchController::class,'substitution'])->where('save','[0-9]+')->where('fixture','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/matches/{fixture}/tactic',[MatchController::class,'tactic'])->where('save','[0-9]+')->where('fixture','[0-9]+')->middleware(...$secure);
};

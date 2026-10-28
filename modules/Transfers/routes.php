<?php
use App\Core\Application;
use App\Core\Router;
use App\Middleware\AuthMiddleware;
use App\Middleware\SaveMiddleware;
use Modules\Transfers\Controllers\TransferController;
$secure=[AuthMiddleware::class,SaveMiddleware::class];
return static function(Router $router,Application $app) use ($secure): void {
 $router->get('/saves/{save}/transfers',[TransferController::class,'index'])->where('save','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/transfers/offers',[TransferController::class,'offer'])->where('save','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/transfers/offers/{offer}/accept',[TransferController::class,'accept'])->where('save','[0-9]+')->where('offer','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/transfers/offers/{offer}/reject',[TransferController::class,'reject'])->where('save','[0-9]+')->where('offer','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/transfers/offers/{offer}/counter',[TransferController::class,'counter'])->where('save','[0-9]+')->where('offer','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/transfers/shortlist/{player}',[TransferController::class,'shortlist'])->where('save','[0-9]+')->where('player','[0-9]+')->middleware(...$secure);
 $router->post('/saves/{save}/transfers/release/{player}',[TransferController::class,'release'])->where('save','[0-9]+')->where('player','[0-9]+')->middleware(...$secure);
};

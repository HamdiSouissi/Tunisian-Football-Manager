<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Router;
use Modules\System\Controllers\StatusController;

return static function (Router $router, Application $app): void {
    $router->get('/system', [StatusController::class, 'index']);
    $router->get('/health', [StatusController::class, 'health']);
};

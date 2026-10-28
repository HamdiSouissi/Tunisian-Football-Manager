<?php

declare(strict_types=1);

namespace Modules\System\Controllers;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;

final class StatusController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('system::status', [
            'modules' => $this->app->modules()->all(),
            'phpVersion' => PHP_VERSION,
        ]);
    }

    public function health(Request $request): Response
    {
        $modules = array_column($this->app->modules()->all(), 'name');

        return $this->json([
            'status' => 'ok',
            'application' => config('app.name'),
            'modules' => $modules,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Modules\Match;

use App\Contracts\MatchEngineInterface;
use App\Contracts\ModuleInterface;
use App\Core\Application;
use App\Engine\TacticalMatchEngine;

final class MatchModule implements ModuleInterface
{
    public function register(Application $app): void
    {
        $app->container()->singleton(TacticalMatchEngine::class);
        $app->container()->singleton(MatchEngineInterface::class, TacticalMatchEngine::class);
    }
    public function boot(Application $app): void {}
}

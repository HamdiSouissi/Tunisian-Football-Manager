<?php

declare(strict_types=1);

namespace Modules\Transfers;

use App\Contracts\AIManagerInterface;
use App\Contracts\ModuleInterface;
use App\Core\Application;
use App\Services\ClubAIManager;

final class TransfersModule implements ModuleInterface
{
    public function register(Application $app): void
    {
        $app->container()->singleton(ClubAIManager::class);
        $app->container()->singleton(AIManagerInterface::class, ClubAIManager::class);
    }
    public function boot(Application $app): void {}
}

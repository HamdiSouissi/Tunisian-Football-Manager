<?php

declare(strict_types=1);

namespace Modules\Auth;

use App\Contracts\ModuleInterface;
use App\Core\Application;
use App\Services\ClubCatalog;
use App\Services\PlayerFactory;

final class AuthModule implements ModuleInterface
{
    public function register(Application $app): void
    {
        $app->container()->singleton(ClubCatalog::class, fn () => new ClubCatalog($app->basePath('database/data')));
        $app->container()->singleton(PlayerFactory::class, fn () => new PlayerFactory($app->basePath('database/data')));
    }

    public function boot(Application $app): void
    {
    }
}

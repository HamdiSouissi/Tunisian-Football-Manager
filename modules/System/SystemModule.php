<?php

declare(strict_types=1);

namespace Modules\System;

use App\Contracts\ModuleInterface;
use App\Core\Application;

/** Module minimal de diagnostic et point d'entrée du socle technique. */
final class SystemModule implements ModuleInterface
{
    public function register(Application $app): void
    {
        // Les futurs services techniques du module seront enregistrés ici.
    }

    public function boot(Application $app): void
    {
        // Les futurs abonnements aux événements du module seront branchés ici.
    }
}

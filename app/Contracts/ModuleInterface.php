<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Core\Application;

interface ModuleInterface
{
    /** Enregistre les services du module dans le conteneur. */
    public function register(Application $app): void;

    /** Branche les hooks et initialise le module après l'enregistrement des services. */
    public function boot(Application $app): void;
}

<?php

declare(strict_types=1);

use App\Core\Autoloader;

$basePath = dirname(__DIR__);

require $basePath . '/app/Core/Autoloader.php';

Autoloader::register($basePath);

require $basePath . '/app/Support/helpers.php';

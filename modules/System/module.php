<?php

declare(strict_types=1);

use Modules\System\SystemModule;

return [
    'name' => 'system',
    'version' => '1.0.0',
    'class' => SystemModule::class,
    'priority' => 10,
    'enabled' => true,
];

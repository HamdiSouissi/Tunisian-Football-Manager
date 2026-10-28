<?php
namespace Modules\Training;
use App\Contracts\ModuleInterface;
use App\Core\Application;
final class TrainingModule implements ModuleInterface { public function register(Application $app): void {} public function boot(Application $app): void {} }

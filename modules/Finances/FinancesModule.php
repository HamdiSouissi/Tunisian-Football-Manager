<?php

declare(strict_types=1);

namespace Modules\Finances;

use App\Contracts\ModuleInterface;
use App\Core\Application;
use App\Events\Event;
use App\Services\FinanceService;

final class FinancesModule implements ModuleInterface
{
    public function register(Application $app): void {}
    public function boot(Application $app): void
    {
        $app->events()->listen('match.finished',static function(Event $event)use($app):void{$app->make(FinanceService::class)->onMatchFinished($event);},20);
        $app->events()->listen('transfer.completed',static function(Event $event)use($app):void{$app->make(FinanceService::class)->onTransferCompleted($event);},20);
    }
}

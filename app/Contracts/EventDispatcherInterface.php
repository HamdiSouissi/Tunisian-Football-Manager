<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Events\Event;

interface EventDispatcherInterface
{
    public function listen(string $eventName, callable $listener, int $priority = 0): void;

    /** @param array<string, mixed> $payload */
    public function dispatch(string $eventName, array $payload = []): Event;
}

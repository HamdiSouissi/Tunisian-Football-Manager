<?php

declare(strict_types=1);

namespace App\Events;

use App\Contracts\EventDispatcherInterface;

/**
 * Dispatcher synchrone : priorités décroissantes et motifs génériques ("match.*").
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<string, list<array{listener: callable, priority: int, order: int}>> */
    private array $listeners = [];

    private int $order = 0;

    public function listen(string $eventName, callable $listener, int $priority = 0): void
    {
        $this->listeners[$eventName][] = [
            'listener' => $listener,
            'priority' => $priority,
            'order' => $this->order++,
        ];
    }

    public function dispatch(string $eventName, array $payload = []): Event
    {
        $event = new Event($eventName, $payload);
        $listeners = [];

        foreach ($this->listeners as $pattern => $registeredListeners) {
            $matches = $pattern === $eventName
                || ((str_contains($pattern, '*') || str_contains($pattern, '?')) && fnmatch($pattern, $eventName));

            if ($matches) {
                $listeners = [...$listeners, ...$registeredListeners];
            }
        }

        usort($listeners, static fn (array $left, array $right): int =>
            [$right['priority'], $left['order']] <=> [$left['priority'], $right['order']]
        );

        foreach ($listeners as $registered) {
            if ($event->isPropagationStopped()) {
                break;
            }
            ($registered['listener'])($event);
        }

        return $event;
    }
}

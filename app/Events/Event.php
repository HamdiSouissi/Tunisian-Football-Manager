<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Événement synchrone transportant un payload typé par clés.
 */
final class Event
{
    private bool $propagationStopped = false;

    /** @param array<string, mixed> $payload */
    public function __construct(
        private readonly string $name,
        private readonly array $payload
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}

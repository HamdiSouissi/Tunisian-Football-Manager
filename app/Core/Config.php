<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Dépôt de configuration avec accès par notation "fichier.clé.sous-clé".
 */
final class Config
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function __construct(string $configPath)
    {
        $files = glob(rtrim($configPath, DIRECTORY_SEPARATOR) . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $values = require $file;
            if (!is_array($values)) {
                throw new RuntimeException(sprintf('Le fichier de configuration %s doit retourner un tableau.', $file));
            }

            $this->items[pathinfo($file, PATHINFO_FILENAME)] = $values;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $target =& $this->items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $target[$segment] = $value;
                break;
            }

            if (!isset($target[$segment]) || !is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target =& $target[$segment];
        }
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->items;
    }
}

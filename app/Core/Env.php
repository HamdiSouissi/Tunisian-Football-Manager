<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Charge un fichier .env simple, sans dépendance externe.
 * Les variables déjà présentes dans l'environnement ont toujours la priorité.
 */
final class Env
{
    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!preg_match('/^([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/i', $line, $matches)) {
                continue;
            }

            $name = $matches[1];
            if (getenv($name) !== false || array_key_exists($name, $_ENV)) {
                continue;
            }

            $value = self::parseValue($matches[2]);
            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }

    private static function parseValue(string $value): string
    {
        $value = trim($value);
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
                return $first === '"' ? stripcslashes($value) : $value;
            }
        }

        // Un commentaire non entouré de guillemets commence après une espace.
        return trim((string) preg_replace('/\s+#.*$/', '', $value));
    }
}

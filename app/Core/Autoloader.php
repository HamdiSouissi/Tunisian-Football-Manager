<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Autoload PSR-4 minimaliste : App\ vers /app et Modules\ vers /modules.
 */
final class Autoloader
{
    /** @var array<string, string> */
    private static array $prefixes = [];

    public static function register(string $basePath): void
    {
        self::addNamespace('App\\', $basePath . '/app');
        self::addNamespace('Modules\\', $basePath . '/modules');

        spl_autoload_register([self::class, 'load'], true, true);
    }

    public static function addNamespace(string $prefix, string $directory): void
    {
        self::$prefixes[$prefix] = rtrim($directory, DIRECTORY_SEPARATOR);
    }

    public static function load(string $class): void
    {
        foreach (self::$prefixes as $prefix => $directory) {
            if (!str_starts_with($class, $prefix)) {
                continue;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = $directory . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass) . '.php';

            if (is_file($file)) {
                require $file;
            }

            return;
        }
    }
}

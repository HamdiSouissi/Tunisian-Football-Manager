<?php

declare(strict_types=1);

namespace App\Core;

use App\Contracts\ModuleInterface;
use Closure;
use RuntimeException;

/**
 * Découvre les manifests /modules/<nom>/module.php et gère leur cycle de vie.
 */
final class ModuleManager
{
    /** @var array<string, array{manifest: array<string, mixed>, instance: ModuleInterface, path: string}> */
    private array $modules = [];

    public function __construct(
        private readonly Application $app,
        private readonly string $modulesPath
    ) {
    }

    public function discover(): void
    {
        $files = glob(rtrim($this->modulesPath, DIRECTORY_SEPARATOR) . '/*/module.php') ?: [];
        $definitions = [];
        $disabled = (array) $this->app->config()->get('modules.disabled', []);

        foreach ($files as $file) {
            $manifest = require $file;
            if (!is_array($manifest)) {
                throw new RuntimeException(sprintf('Le manifest %s doit retourner un tableau.', $file));
            }

            $name = (string) ($manifest['name'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9-]*$/', $name)) {
                throw new RuntimeException(sprintf('Nom de module invalide dans %s.', $file));
            }
            if (in_array($name, $disabled, true) || ($manifest['enabled'] ?? true) === false) {
                continue;
            }
            if (isset($definitions[$name])) {
                throw new RuntimeException(sprintf('Le module "%s" est déclaré plusieurs fois.', $name));
            }

            $manifest['directory'] = dirname($file);
            $definitions[$name] = $manifest;
        }

        uasort($definitions, static fn (array $left, array $right): int =>
            ((int) ($left['priority'] ?? 100)) <=> ((int) ($right['priority'] ?? 100))
        );

        foreach ($definitions as $name => $manifest) {
            $class = $manifest['class'] ?? null;
            if (!is_string($class) || !is_subclass_of($class, ModuleInterface::class)) {
                throw new RuntimeException(sprintf('Le module "%s" doit déclarer une classe implémentant ModuleInterface.', $name));
            }

            $instance = $this->app->make($class);
            if (!$instance instanceof ModuleInterface) {
                throw new RuntimeException(sprintf('Impossible d’initialiser le module "%s".', $name));
            }

            $path = (string) $manifest['directory'];
            $this->modules[$name] = [
                'manifest' => $manifest,
                'instance' => $instance,
                'path' => $path,
            ];

            /** @var View $view */
            $view = $this->app->make(View::class);
            $view->addNamespace($name, $path . '/views');

            $instance->register($this->app);
        }
    }

    public function boot(): void
    {
        foreach ($this->modules as $module) {
            $module['instance']->boot($this->app);
        }
    }

    public function loadRoutes(Router $router): void
    {
        foreach ($this->modules as $name => $module) {
            $file = $module['path'] . '/routes.php';
            if (!is_file($file)) {
                continue;
            }

            $register = require $file;
            if (!$register instanceof Closure) {
                throw new RuntimeException(sprintf('Le fichier de routes du module "%s" doit retourner une Closure.', $name));
            }

            $register($router, $this->app);
        }
    }

    /** @return array<string, string> Chemins de migrations indexés par nom de module. */
    public function migrationPaths(): array
    {
        $paths = [];
        foreach ($this->modules as $name => $module) {
            $path = $module['path'] . '/migrations';
            if (is_dir($path)) {
                $paths[$name] = $path;
            }
        }
        return $paths;
    }

    /** @return list<array{name: string, version: string, priority: int}> */
    public function all(): array
    {
        return array_map(static fn (array $module): array => [
            'name' => (string) $module['manifest']['name'],
            'version' => (string) ($module['manifest']['version'] ?? '1.0.0'),
            'priority' => (int) ($module['manifest']['priority'] ?? 100),
        ], array_values($this->modules));
    }
}

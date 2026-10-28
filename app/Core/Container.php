<?php

declare(strict_types=1);

namespace App\Core;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * Conteneur de services léger avec autowiring des dépendances typées.
 */
final class Container
{
    /** @var array<string, array{factory: Closure|string, shared: bool}> */
    private array $bindings = [];

    /** @var array<string, object> */
    private array $instances = [];

    /** @var list<string> */
    private array $buildStack = [];

    public function bind(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'factory' => $concrete ?? $abstract,
            'shared' => false,
        ];
        unset($this->instances[$abstract]);
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): void
    {
        $this->bindings[$abstract] = [
            'factory' => $concrete ?? $abstract,
            'shared' => true,
        ];
        unset($this->instances[$abstract]);
    }

    public function instance(string $abstract, object $instance): void
    {
        $this->instances[$abstract] = $instance;
    }

    public function has(string $abstract): bool
    {
        return isset($this->instances[$abstract]) || isset($this->bindings[$abstract]) || class_exists($abstract);
    }

    public function get(string $abstract): object
    {
        if (isset($this->instances[$abstract])) {
            return $this->instances[$abstract];
        }

        if (isset($this->bindings[$abstract])) {
            $binding = $this->bindings[$abstract];
            $factory = $binding['factory'];
            $object = $factory instanceof Closure ? $factory($this) : $this->resolve($factory);

            if ($binding['shared']) {
                $this->instances[$abstract] = $object;
            }

            return $object;
        }

        return $this->resolve($abstract);
    }

    private function resolve(string $class): object
    {
        if (in_array($class, $this->buildStack, true)) {
            throw new RuntimeException(sprintf('Dépendance circulaire détectée : %s.', implode(' -> ', [...$this->buildStack, $class])));
        }

        if (!class_exists($class)) {
            throw new RuntimeException(sprintf('La classe ou le service "%s" est introuvable.', $class));
        }

        $this->buildStack[] = $class;

        try {
            $reflection = new ReflectionClass($class);
            if (!$reflection->isInstantiable()) {
                throw new RuntimeException(sprintf('La classe "%s" ne peut pas être instanciée.', $class));
            }

            $constructor = $reflection->getConstructor();
            if ($constructor === null) {
                return new $class();
            }

            $dependencies = [];
            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                    $dependencies[] = $this->get($type->getName());
                    continue;
                }

                if ($parameter->isDefaultValueAvailable()) {
                    $dependencies[] = $parameter->getDefaultValue();
                    continue;
                }

                if ($parameter->allowsNull()) {
                    $dependencies[] = null;
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Impossible de résoudre le paramètre $%s du constructeur de %s.',
                    $parameter->getName(),
                    $class
                ));
            }

            return $reflection->newInstanceArgs($dependencies);
        } finally {
            array_pop($this->buildStack);
        }
    }
}

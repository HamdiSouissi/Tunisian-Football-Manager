<?php

declare(strict_types=1);

namespace App\Core;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Moteur de vues PHP avec espaces de noms propres aux modules ("system::status").
 */
final class View
{
    /** @var array<string, string> */
    private array $namespaces = [];

    public function __construct(private readonly string $basePath)
    {
    }

    public function addNamespace(string $namespace, string $path): void
    {
        $this->namespaces[$namespace] = rtrim($path, DIRECTORY_SEPARATOR);
    }

    /** @param array<string, mixed> $data */
    public function render(string $template, array $data = []): string
    {
        $file = $this->find($template);

        return (static function (string $__file, array $__data): string {
            ob_start();
            try {
                extract($__data, EXTR_SKIP);
                require $__file;
                return (string) ob_get_clean();
            } catch (Throwable $exception) {
                ob_end_clean();
                throw $exception;
            }
        })($file, $data);
    }

    private function find(string $template): string
    {
        if (str_contains($template, "\0") || str_contains($template, '..')) {
            throw new InvalidArgumentException('Nom de vue invalide.');
        }

        $basePath = $this->basePath;
        $name = $template;

        if (str_contains($template, '::')) {
            [$namespace, $name] = explode('::', $template, 2);
            if (!isset($this->namespaces[$namespace])) {
                throw new RuntimeException(sprintf('Espace de vues "%s" non enregistré.', $namespace));
            }
            $basePath = $this->namespaces[$namespace];
        }

        $file = $basePath . '/' . str_replace('.', '/', $name) . '.php';
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Vue "%s" introuvable (%s).', $template, $file));
        }

        return $file;
    }
}

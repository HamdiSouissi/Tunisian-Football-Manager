<?php

declare(strict_types=1);

namespace App\Core;

use Closure;

/**
 * Route HTTP et ses contraintes de paramètres.
 */
final class Route
{
    /** @var array<string, string> */
    private array $constraints = [];

    /** @var list<class-string> */
    private array $middleware = [];

    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly Closure|array|string $action
    ) {
    }

    public function where(string $parameter, string $pattern): self
    {
        $this->constraints[$parameter] = $pattern;
        return $this;
    }

    /** @param class-string ...$middleware */
    public function middleware(string ...$middleware): self
    {
        $this->middleware = [...$this->middleware, ...array_values($middleware)];
        return $this;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function action(): Closure|array|string
    {
        return $this->action;
    }

    /** @return list<class-string> */
    public function middlewares(): array
    {
        return $this->middleware;
    }

    /** @return array<string, string>|null Null si le chemin ne correspond pas. */
    public function parametersFor(string $path): ?array
    {
        $regex = $this->compileRegex();
        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        $parameters = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = rawurldecode((string) $value);
            }
        }

        return $parameters;
    }

    private function compileRegex(): string
    {
        $regex = '';
        $offset = 0;
        $path = $this->path === '' ? '/' : $this->path;

        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', $path, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $index => $placeholder) {
            [$token, $position] = $placeholder;
            $name = $matches[1][$index][0];
            $regex .= preg_quote(substr($path, $offset, $position - $offset), '#');
            $constraint = $this->constraints[$name] ?? '[^/]+';
            $regex .= '(?P<' . $name . '>' . $constraint . ')';
            $offset = $position + strlen($token);
        }

        $regex .= preg_quote(substr($path, $offset), '#');
        return '#^' . $regex . '/?$#uD';
    }
}

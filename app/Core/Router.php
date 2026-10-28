<?php

declare(strict_types=1);

namespace App\Core;

use App\Contracts\MiddlewareInterface;
use Closure;
use RuntimeException;
use UnexpectedValueException;

/**
 * Routeur REST avec paramètres, middlewares et actions de contrôleurs.
 */
final class Router
{
    /** @var array<string, list<Route>> */
    private array $routes = [];

    /** @var list<class-string> */
    private array $globalMiddleware = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function get(string $path, Closure|array|string $action): Route
    {
        return $this->add('GET', $path, $action);
    }

    public function post(string $path, Closure|array|string $action): Route
    {
        return $this->add('POST', $path, $action);
    }

    public function put(string $path, Closure|array|string $action): Route
    {
        return $this->add('PUT', $path, $action);
    }

    public function patch(string $path, Closure|array|string $action): Route
    {
        return $this->add('PATCH', $path, $action);
    }

    public function delete(string $path, Closure|array|string $action): Route
    {
        return $this->add('DELETE', $path, $action);
    }

    public function add(string $method, string $path, Closure|array|string $action): Route
    {
        $method = strtoupper($method);
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $route = new Route($method, $path, $action);
        $this->routes[$method][] = $route;
        return $route;
    }

    /** @param class-string ...$middleware */
    public function addGlobalMiddleware(string ...$middleware): void
    {
        $this->globalMiddleware = [...$this->globalMiddleware, ...array_values($middleware)];
    }

    public function dispatch(Request $request): Response
    {
        $allowedMethods = [];
        $method = $request->method();

        foreach ($this->routes as $routeMethod => $routes) {
            foreach ($routes as $route) {
                $parameters = $route->parametersFor($request->path());
                if ($parameters === null) {
                    continue;
                }

                $allowedMethods[] = $routeMethod;
                if ($routeMethod !== $method) {
                    continue;
                }

                $request->setRouteParameters($parameters);
                return $this->runRoute($route, $request);
            }
        }

        if ($allowedMethods !== []) {
            return Response::html($this->errorPage('405', 'Méthode non autorisée.'), 405)
                ->header('Allow', implode(', ', array_unique($allowedMethods)));
        }

        return Response::html($this->errorPage('404', 'La page demandée est introuvable.'), 404);
    }

    private function runRoute(Route $route, Request $request): Response
    {
        $destination = fn (Request $currentRequest): Response => $this->callAction($route, $currentRequest);
        $middlewares = [...$this->globalMiddleware, ...$route->middlewares()];
        $next = $destination;

        foreach (array_reverse($middlewares) as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);
            if (!$middleware instanceof MiddlewareInterface) {
                throw new RuntimeException(sprintf('Le middleware "%s" doit implémenter MiddlewareInterface.', $middlewareClass));
            }

            $next = static fn (Request $currentRequest): Response => $middleware->handle($currentRequest, $next);
        }

        return $next($request);
    }

    private function callAction(Route $route, Request $request): Response
    {
        $action = $route->action();
        $parameters = [];
        foreach ($this->extractRouteParameters($request, $route) as $value) {
            $parameters[] = $value;
        }

        if (is_string($action) && str_contains($action, '@')) {
            [$controllerClass, $method] = explode('@', $action, 2);
            $action = [$controllerClass, $method];
        }

        if (is_array($action) && is_string($action[0])) {
            $action[0] = $this->container->get($action[0]);
        }

        $result = $action($request, ...$parameters);
        if (!$result instanceof Response) {
            throw new UnexpectedValueException('Une action de route doit retourner une instance de Response.');
        }

        return $result;
    }

    /** @return array<string, string> */
    private function extractRouteParameters(Request $request, Route $route): array
    {
        // La correspondance est rejouée pour conserver l'ordre déclaré des paramètres.
        return $route->parametersFor($request->path()) ?? [];
    }

    private function errorPage(string $code, string $message): string
    {
        return '<!doctype html><html lang="fr"><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
            . '<title>' . $code . '</title><body style="background:#101827;color:#e5e7eb;font-family:sans-serif;padding:2rem">'
            . '<h1>' . $code . '</h1><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '<a style="color:#5eead4" href="/">Retour à l’accueil</a></body></html>';
    }
}

<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Security\Csrf;
use Closure;

/**
 * Vérifie toute requête modifiant l'état, y compris les appels fetch/json.
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Csrf $csrf)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->input('_token');
        if (!is_string($token)) {
            $token = $request->header('x-csrf-token');
        }

        if (!$this->csrf->validate($token)) {
            if ($request->expectsJson()) {
                return Response::json(['message' => 'La session a expiré. Rechargez la page et réessayez.'], 419);
            }
            return Response::html('Jeton CSRF invalide ou session expirée.', 419);
        }

        return $next($request);
    }
}

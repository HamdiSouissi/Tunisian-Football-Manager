<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuthService;
use Closure;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!$this->auth->check()) {
            return $request->expectsJson()
                ? Response::json(['message' => 'Authentification requise.'], 401)
                : Response::redirect('/login');
        }
        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Contracts\MiddlewareInterface;
use App\Core\Request;
use App\Core\Response;
use App\Services\GameContext;
use Closure;
use Throwable;

final class SaveMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly GameContext $context)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $this->context->load((int) $request->route('save'));
            $request->setAttribute('save', $this->context->save());
        } catch (Throwable) {
            return $request->expectsJson()
                ? Response::json(['message' => 'Sauvegarde introuvable.'], 404)
                : Response::html('<h1>Sauvegarde introuvable</h1><p><a href="/saves">Retour aux parties</a></p>', 404);
        }
        return $next($request);
    }
}

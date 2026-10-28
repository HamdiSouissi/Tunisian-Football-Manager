<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Core\Request;
use App\Core\Response;
use Closure;

interface MiddlewareInterface
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response;
}

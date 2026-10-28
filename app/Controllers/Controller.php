<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\Response;
use App\Core\View;

/** Contrôleur de base : aucune logique métier, uniquement des aides HTTP. */
abstract class Controller
{
    public function __construct(protected readonly Application $app)
    {
    }

    /** @param array<string, mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        /** @var View $view */
        $view = $this->app->make(View::class);
        return Response::html($view->render($template, $data), $status);
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

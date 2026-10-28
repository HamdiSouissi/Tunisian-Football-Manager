<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Application;
use App\Core\ErrorHandler;
use App\Core\Response;
use App\Core\View;
use App\Security\ValidationException;
use PDOException;
use RuntimeException;
use Throwable;

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

    protected function exceptionMessage(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            return implode(' ', $exception->errors());
        }
        if ($exception instanceof RuntimeException && !$exception instanceof PDOException) {
            return $exception->getMessage();
        }
        $this->app->make(ErrorHandler::class)->report($exception);
        return 'Une erreur technique est survenue. Veuillez réessayer.';
    }
}

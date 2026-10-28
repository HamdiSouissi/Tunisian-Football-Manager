<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Response;
use App\Core\View;
use App\Services\GameContext;

/** Base des écrans authentifiés partageant le layout de jeu. */
abstract class GameController extends Controller
{
    protected function gameView(string $template, array $data = [], int $status = 200): Response
    {
        /** @var View $view */
        $view = $this->app->make(View::class);
        /** @var GameContext $context */
        $context = $this->app->make(GameContext::class);
        $data['context'] = $context;
        $content = $view->render($template, $data);

        return Response::html($view->render('game::layout', array_merge($data, [
            'content' => $content,
            'context' => $context,
            'title' => $data['title'] ?? 'Tunisian Football Manager',
            'active' => $data['active'] ?? 'dashboard',
        ])), $status);
    }

    protected function saveUrl(string $path = ''): string
    {
        /** @var GameContext $context */
        $context = $this->app->make(GameContext::class);
        return '/saves/' . $context->saveId() . ($path !== '' ? '/' . ltrim($path, '/') : '');
    }
}

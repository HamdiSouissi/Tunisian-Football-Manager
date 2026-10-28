<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use App\Controllers\Controller;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Security\ValidationException;
use App\Services\AuthService;
use App\Session\Session;
use Throwable;

final class AuthController extends Controller
{
    public function __construct(Application $app, private readonly AuthService $auth, private readonly Session $session)
    {
        parent::__construct($app);
    }

    public function home(Request $request): Response
    {
        return $this->redirect($this->auth->check() ? '/saves' : '/login');
    }

    public function loginForm(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/saves');
        }
        return $this->page('auth::login', ['title' => 'Connexion']);
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        if ($this->auth->attempt($email, (string) $request->input('password', ''))) {
            return $this->redirect('/saves');
        }
        $this->session->flash('error', 'Identifiants incorrects ou compte désactivé.');
        $this->session->flash('old_email', $email);
        return $this->redirect('/login');
    }

    public function registerForm(Request $request): Response
    {
        if ($this->auth->check()) {
            return $this->redirect('/saves');
        }
        return $this->page('auth::register', ['title' => 'Inscription', 'old' => $this->session->pullFlash('old', [])]);
    }

    public function register(Request $request): Response
    {
        try {
            $this->auth->register(
                (string) $request->input('name', ''),
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
                (string) $request->input('password_confirmation', '')
            );
            return $this->redirect('/saves/create');
        } catch (ValidationException $exception) {
            $this->session->flash('errors', $exception->errors());
            $this->session->flash('old', $request->only(['name','email']));
            return $this->redirect('/register');
        }
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();
        return $this->redirect('/login');
    }

    private function page(string $template, array $data): Response
    {
        $view = $this->app->make(View::class);
        $content = $view->render($template, $data);
        return Response::html($view->render('auth::layout', array_merge($data, ['content' => $content, 'auth' => $this->auth])));
    }
}

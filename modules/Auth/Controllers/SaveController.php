<?php

declare(strict_types=1);

namespace Modules\Auth\Controllers;

use App\Controllers\Controller;
use App\Core\Application;
use App\Core\Config;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Database\Database;
use App\Security\ValidationException;
use App\Services\AuthService;
use App\Services\ClubCatalog;
use App\Services\WorldSeeder;
use App\Session\Session;
use Throwable;

final class SaveController extends Controller
{
    public function __construct(
        Application $app,
        private readonly Database $database,
        private readonly AuthService $auth,
        private readonly ClubCatalog $catalog,
        private readonly WorldSeeder $seeder,
        private readonly Config $config,
        private readonly Session $session
    ) {
        parent::__construct($app);
    }

    public function index(Request $request): Response
    {
        $saves = $this->database->select(
            'SELECT s.*, c.name AS club_name, c.primary_color, l.short_name AS division_name
             FROM saves s LEFT JOIN clubs c ON c.id = s.club_id LEFT JOIN leagues l ON l.id = c.league_id
             WHERE s.user_id = :user_id ORDER BY s.updated_at DESC',
            ['user_id' => $this->auth->userId()]
        );
        return $this->page('auth::saves', ['title' => 'Mes parties', 'saves' => $saves]);
    }

    public function create(Request $request): Response
    {
        return $this->page('auth::create_save', [
            'title' => 'Nouvelle partie', 'leagues' => $this->catalog->leagues(),
            'difficulties' => $this->config->get('game.difficulties', []), 'user' => $this->auth->user(),
        ]);
    }

    public function store(Request $request): Response
    {
        try {
            $saveId = $this->seeder->createSave(
                $this->auth->userId(), (string) $request->input('name', 'Nouvelle carrière'),
                (string) $request->input('manager_name', ''), (string) $request->input('difficulty', 'normal'),
                (int) $request->input('division', 1), (string) $request->input('club_slug', '')
            );
            $this->session->flash('success', 'Votre monde de jeu a été généré avec succès.');
            return $this->redirect('/saves/' . $saveId);
        } catch (ValidationException $exception) {
            $this->session->flash('errors', $exception->errors());
            return $this->redirect('/saves/create');
        }
    }

    public function archive(Request $request, string $save): Response
    {
        $this->database->execute(
            'UPDATE saves SET status = CASE WHEN status = \'active\' THEN \'archived\' WHEN status = \'archived\' THEN \'active\' ELSE status END WHERE id = :save_id AND user_id = :user_id',
            ['save_id' => (int) $save, 'user_id' => $this->auth->userId()]
        );
        return $this->redirect('/saves');
    }

    private function page(string $template, array $data): Response
    {
        $view = $this->app->make(View::class);
        $content = $view->render($template, $data);
        return Response::html($view->render('auth::layout', array_merge($data, ['content' => $content, 'auth' => $this->auth])));
    }
}

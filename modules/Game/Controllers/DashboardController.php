<?php

declare(strict_types=1);

namespace Modules\Game\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Services\DashboardService;
use App\Services\GameContext;
use App\Services\GameLoopService;
use App\Services\MessageService;
use App\Session\Session;
use Throwable;

final class DashboardController extends GameController
{
    public function __construct(
        Application $app,
        private readonly GameContext $context,
        private readonly DashboardService $dashboard,
        private readonly MessageService $messages,
        private readonly GameLoopService $gameLoop,
        private readonly Database $database,
        private readonly Session $session
    ) {
        parent::__construct($app);
    }

    public function index(Request $request): Response
    {
        return $this->gameView('game::dashboard', array_merge($this->dashboard->summary($this->context), [
            'title' => 'Accueil', 'active' => 'dashboard', 'save' => $this->context->save(), 'club' => $this->context->club(),
        ]));
    }

    public function advance(Request $request): Response
    {
        $days = min(7, max(1, (int) $request->input('days', 1)));
        try {
            $result = $this->gameLoop->advance($this->context, $days);
            $message = sprintf('%d jour(s) écoulé(s), %d match(s) simulé(s), %d entraînement(s) appliqué(s).', $result['days'], $result['matches'], $result['training']);
            if ($result['awaiting_match'] > 0) {
                $message .= ' Votre match du jour est prêt : cliquez sur « Jouer le match ».';
            }
            $this->session->flash('success', $message);
        } catch (Throwable $exception) {
            $this->session->flash('error', $this->exceptionMessage($exception));
        }
        return $this->redirect($this->saveUrl());
    }

    public function inbox(Request $request): Response
    {
        return $this->gameView('game::inbox', ['title' => 'Boîte de réception', 'active' => 'inbox', 'messages' => $this->messages->inbox($this->context)]);
    }

    public function readMessage(Request $request, string $save, string $message): Response
    {
        $this->messages->markRead($this->context, (int) $message);
        if ($request->expectsJson()) {
            return $this->json(['ok' => true]);
        }
        return $this->redirect($this->saveUrl('inbox'));
    }

    public function club(Request $request): Response
    {
        return $this->gameView('game::club', array_merge($this->clubData(), ['title' => 'Club', 'active' => 'club']));
    }

    public function staff(Request $request): Response
    {
        return $this->gameView('game::staff', array_merge($this->clubData(), ['title' => 'Staff', 'active' => 'staff']));
    }

    private function clubData(): array
    {
        $staff = $this->database->select(
            'SELECT * FROM staff WHERE save_id = :save_id AND club_id = :club_id ORDER BY role, quality DESC',
            ['save_id' => $this->context->saveId(), 'club_id' => $this->context->clubId()]
        );
        $objective = $this->database->selectOne(
            'SELECT * FROM board_objectives WHERE save_id = :save_id AND season_id = :season_id AND club_id = :club_id AND is_primary = 1',
            ['save_id' => $this->context->saveId(), 'season_id' => (int) $this->context->save()['current_season_id'], 'club_id' => $this->context->clubId()]
        );
        return ['club' => $this->context->club(), 'save' => $this->context->save(), 'staffMembers' => $staff, 'objective' => $objective];
    }
}

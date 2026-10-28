<?php

declare(strict_types=1);

namespace Modules\Squad\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Models\PlayerRepository;
use App\Services\GameContext;
use App\Services\PlayerFactory;

final class SquadController extends GameController
{
    public function __construct(Application $app, private readonly GameContext $context, private readonly PlayerRepository $players, private readonly Database $database)
    {
        parent::__construct($app);
    }

    public function index(Request $request): Response
    {
        $players = $this->players->forClub($this->context->saveId(), $this->context->clubId());
        return $this->gameView('squad::index', ['title' => 'Effectif', 'active' => 'squad', 'players' => $players, 'save' => $this->context->save()]);
    }

    public function show(Request $request, string $save, string $player): Response
    {
        $data = $this->players->find($this->context->saveId(), (int) $player);
        if ($data === null) {
            return Response::html('Joueur introuvable', 404);
        }
        $history = $this->database->select(
            'SELECT t.*, fc.name AS from_name, tc.name AS to_name FROM transfers t
             LEFT JOIN clubs fc ON fc.id = t.from_club_id LEFT JOIN clubs tc ON tc.id = t.to_club_id
             WHERE t.save_id = :save_id AND t.player_id = :player_id ORDER BY t.transfer_date DESC LIMIT 20',
            ['save_id' => $this->context->saveId(), 'player_id' => (int) $player]
        );
        $progress = $this->database->select(
            'SELECT e.*, r.week_start FROM training_report_entries e JOIN training_reports r ON r.id = e.training_report_id
             WHERE e.save_id = :save_id AND e.player_id = :player_id ORDER BY r.week_start DESC LIMIT 12',
            ['save_id' => $this->context->saveId(), 'player_id' => (int) $player]
        );
        $groups = [
            'Technique' => (int) round(((int)$data['passing'] + (int)$data['dribbling'] + (int)$data['shooting'] + (int)$data['first_touch']) / 4),
            'Mental' => (int) round(((int)$data['vision'] + (int)$data['composure'] + (int)$data['work_rate'] + (int)$data['positioning']) / 4),
            'Physique' => (int) round(((int)$data['pace'] + (int)$data['stamina'] + (int)$data['strength'] + (int)$data['jumping']) / 4),
            'Défense' => (int) round(((int)$data['tackling'] + (int)$data['positioning'] + (int)$data['strength']) / 3),
            'Attaque' => (int) round(((int)$data['shooting'] + (int)$data['dribbling'] + (int)$data['composure']) / 3),
            'Gardien' => (int) round(((int)$data['reflexes'] + (int)$data['handling'] + (int)$data['aerial_reach'] + (int)$data['command_of_area']) / 4),
        ];
        return $this->gameView('squad::show', ['title' => $data['first_name'].' '.$data['last_name'], 'active' => 'squad', 'player' => $data, 'attributes' => PlayerFactory::ATTRIBUTES, 'radar' => $groups, 'history' => $history, 'progress' => $progress, 'save' => $this->context->save()]);
    }
}

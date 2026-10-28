<?php

declare(strict_types=1);

namespace Modules\Tactics\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Models\PlayerRepository;
use App\Security\ValidationException;
use App\Services\GameContext;
use App\Services\TacticService;
use App\Session\Session;
use JsonException;

final class TacticsController extends GameController
{
    public function __construct(Application $app, private readonly GameContext $context, private readonly TacticService $tactics, private readonly PlayerRepository $players, private readonly Session $session)
    {
        parent::__construct($app);
    }

    public function index(Request $request): Response
    {
        $tactic = $this->tactics->getDefault($this->context->saveId(), $this->context->clubId());
        $players = $this->tactics->availablePlayers($this->context->saveId(), $this->context->clubId());
        $playerData = array_map(static fn ($p) => ['id'=>(int)$p['id'],'name'=>$p['first_name'].' '.$p['last_name'],'position'=>$p['primary_position'],'ovr'=>(int)$p['overall_rating'],'fatigue'=>(int)$p['fatigue'],'injury'=>$p['injury_type']], $players);
        return $this->gameView('tactics::index', ['title'=>'Tactique','active'=>'tactics','tactic'=>$tactic,'players'=>$players,'playerData'=>$playerData,'formations'=>TacticService::FORMATIONS]);
    }

    public function save(Request $request): Response
    {
        try {
            $lineup = json_decode((string) $request->input('lineup','[]'), true, 512, JSON_THROW_ON_ERROR);
            $substitutes = json_decode((string) $request->input('substitutes','[]'), true, 512, JSON_THROW_ON_ERROR);
            $input = $request->all();
            $input['lineup'] = $lineup;
            $input['substitutes'] = $substitutes;
            $this->tactics->save($this->context->saveId(), $this->context->clubId(), $input);
            $this->session->flash('success','Tactique enregistrée. Les consignes seront utilisées par le moteur de match.');
        } catch (ValidationException|JsonException $exception) {
            $this->session->flash('error',$this->exceptionMessage($exception));
        }
        return $this->redirect($this->saveUrl('tactics'));
    }
}

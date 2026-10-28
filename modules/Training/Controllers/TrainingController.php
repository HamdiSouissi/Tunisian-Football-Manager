<?php

declare(strict_types=1);

namespace Modules\Training\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Models\PlayerRepository;
use App\Services\GameContext;
use App\Services\PlayerFactory;
use App\Services\TrainingService;
use App\Session\Session;
use Throwable;

final class TrainingController extends GameController
{
    public function __construct(Application $app,private readonly GameContext $context,private readonly TrainingService $training,private readonly PlayerRepository $players,private readonly Session $session)
    { parent::__construct($app); }
    public function index(Request $request): Response
    {
        return $this->gameView('training::index',['title'=>'Entraînement','active'=>'training','plan'=>$this->training->currentPlan($this->context),'players'=>$this->players->forClub($this->context->saveId(),$this->context->clubId()),'report'=>$this->training->latestReport($this->context),'attributes'=>PlayerFactory::ATTRIBUTES]);
    }
    public function save(Request $request): Response
    {
        try{$this->training->savePlan($this->context,(string)$request->input('focus','tactical'),(string)$request->input('intensity','medium'),(array)$request->input('individual',[]));$this->session->flash('success','Programme hebdomadaire enregistré. Il sera appliqué au prochain lundi.');}
        catch(Throwable $e){$this->session->flash('error',$this->exceptionMessage($e));}
        return $this->redirect($this->saveUrl('training'));
    }
}

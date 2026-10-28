<?php
namespace Modules\Finances\Controllers;
use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Services\FinanceService;
use App\Services\GameContext;
final class FinancesController extends GameController
{
 public function __construct(Application $app,private readonly GameContext $context,private readonly FinanceService $finances){parent::__construct($app);}
 public function index(Request $request): Response{return $this->gameView('finances::index',array_merge($this->finances->dashboard($this->context),['title'=>'Finances','active'=>'finances','club'=>$this->context->club()]));}
}

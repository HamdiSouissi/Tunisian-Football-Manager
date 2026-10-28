<?php

declare(strict_types=1);

namespace Modules\Transfers\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Models\PlayerRepository;
use App\Services\GameContext;
use App\Services\TransferService;
use App\Session\Session;
use Throwable;

final class TransferController extends GameController
{
    public function __construct(Application $app,private readonly GameContext $context,private readonly TransferService $transfers,private readonly PlayerRepository $players,private readonly Database $database,private readonly Session $session)
    { parent::__construct($app); }

    public function index(Request $request): Response
    {
        $filters=$request->only(['position','club_id','free_agent','min_ovr','max_value','max_age','contract','q']);$filters['date']=$this->context->save()['current_date'];
        $results=$this->players->search($this->context->saveId(),$filters);
        $shortlist=array_fill_keys(array_map(static fn($p)=>(int)$p['id'],$this->transfers->shortlist($this->context)),true);
        $clubs=$this->database->select('SELECT id,name FROM clubs WHERE save_id=:save_id ORDER BY name',['save_id'=>$this->context->saveId()]);
        return $this->gameView('transfers::index',['title'=>'Transferts','active'=>'transfers','results'=>$results,'clubs'=>$clubs,'offers'=>$this->transfers->offers($this->context),'history'=>$this->transfers->history($this->context),'shortlistPlayers'=>$this->transfers->shortlist($this->context),'shortlist'=>$shortlist,'windowOpen'=>$this->transfers->isWindowOpen((string)$this->context->save()['current_date']),'filters'=>$filters,'tab'=>(string)$request->query('tab','search')]);
    }

    public function offer(Request $request): Response { return $this->action(fn()=>$this->transfers->makeOffer($this->context,$request->all()),'Offre traitée. Consultez les offres et votre messagerie.','offers'); }
    public function accept(Request $request,string $save,string $offer): Response { return $this->action(function()use($offer){$this->transfers->accept($this->context,(int)$offer);},'Offre acceptée, transfert finalisé.','history'); }
    public function reject(Request $request,string $save,string $offer): Response { return $this->action(function()use($offer){$this->transfers->reject($this->context,(int)$offer);},'Offre refusée.','offers'); }
    public function counter(Request $request,string $save,string $offer): Response { return $this->action(fn()=>$this->transfers->counter($this->context,(int)$offer,(float)$request->input('amount',0),(float)$request->input('wage',0)),'Contre-proposition envoyée.','offers'); }
    public function shortlist(Request $request,string $save,string $player): Response
    {
        $added=$this->transfers->toggleShortlist($this->context,(int)$player);
        return $request->expectsJson()?$this->json(['shortlisted'=>$added]):$this->redirect($this->saveUrl('transfers'));
    }
    public function release(Request $request,string $save,string $player): Response { return $this->action(function()use($player){$this->transfers->release($this->context,(int)$player);},'Contrat résilié.','history'); }

    private function action(callable $callback,string $success,string $tab): Response
    {
        try{$callback();$this->session->flash('success',$success);}catch(Throwable $e){$this->session->flash('error',$this->exceptionMessage($e));}
        return $this->redirect($this->saveUrl('transfers?tab='.$tab));
    }
}

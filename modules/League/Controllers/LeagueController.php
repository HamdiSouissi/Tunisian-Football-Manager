<?php

declare(strict_types=1);

namespace Modules\League\Controllers;

use App\Controllers\GameController;
use App\Core\Application;
use App\Core\Request;
use App\Core\Response;
use App\Database\Database;
use App\Engine\CalendarEngine;
use App\Services\CalendarService;
use App\Services\GameContext;
use App\Services\LeagueService;
use App\Session\Session;
use DateTimeImmutable;
use Throwable;

final class LeagueController extends GameController
{
    public function __construct(
        Application $app,
        private readonly GameContext $context,
        private readonly Database $database,
        private readonly LeagueService $league,
        private readonly CalendarEngine $calendarEngine,
        private readonly CalendarService $calendar,
        private readonly Session $session
    ) {
        parent::__construct($app);
    }

    public function calendar(Request $request): Response
    {
        $save = $this->context->save();
        $leagueId = (int)$request->query('league', (int)$this->context->club()['league_id']);
        $viewMode = (string)$request->query('view', 'month');
        if (!in_array($viewMode, ['month', 'week', 'list'], true)) $viewMode = 'month';
        $anchorDate = (string)$request->query('date', (string)$save['current_date']);
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $anchorDate);
        if (!$parsedDate || $parsedDate->format('Y-m-d') !== $anchorDate) $anchorDate = (string)$save['current_date'];
        $matchday = max(0, (int)$request->query('matchday', 0));

        $calendarView = null;
        if ($viewMode === 'month') $calendarView = $this->calendarEngine->month($anchorDate, [], $this->context->clubId(), (string)$save['current_date']);
        if ($viewMode === 'week') $calendarView = $this->calendarEngine->week($anchorDate, [], $this->context->clubId(), (string)$save['current_date']);

        $bindings = ['save_id'=>$this->context->saveId(), 'season_id'=>(int)$save['current_season_id'], 'league_id'=>$leagueId];
        $where = 'f.save_id=:save_id AND f.season_id=:season_id AND f.league_id=:league_id';
        if ($matchday > 0 && $viewMode === 'list') {
            $where .= ' AND f.matchday=:matchday';
            $bindings['matchday'] = $matchday;
        }
        if ($calendarView !== null) {
            $where .= ' AND DATE(f.scheduled_at) BETWEEN :period_start AND :period_end';
            $bindings['period_start'] = $calendarView['start'];
            $bindings['period_end'] = $calendarView['end'];
        }
        $fixtures = $this->database->select(
            'SELECT f.*,h.name AS home_name,h.short_name AS home_short,h.slug AS home_slug,h.primary_color AS home_color,
                    a.name AS away_name,a.short_name AS away_short,a.slug AS away_slug,a.primary_color AS away_color
             FROM fixtures f JOIN clubs h ON h.id=f.home_club_id JOIN clubs a ON a.id=f.away_club_id
             WHERE ' . $where . ' ORDER BY f.scheduled_at,f.matchday,f.id',
            $bindings
        );
        if ($viewMode === 'month') $calendarView = $this->calendarEngine->month($anchorDate, $fixtures, $this->context->clubId(), (string)$save['current_date']);
        if ($viewMode === 'week') $calendarView = $this->calendarEngine->week($anchorDate, $fixtures, $this->context->clubId(), (string)$save['current_date']);

        return $this->gameView('league::calendar', array_merge($this->leagueData($leagueId), [
            'title'=>'Calendrier & résultats', 'active'=>'calendar', 'fixtures'=>$fixtures,
            'selectedMatchday'=>$matchday, 'save'=>$save, 'viewMode'=>$viewMode,
            'calendarView'=>$calendarView, 'anchorDate'=>$anchorDate,
            'nextFixture'=>$this->calendar->nextFixture($this->context),
            'recentResults'=>$this->calendar->recentResults($this->context),
        ]));
    }

    public function advance(Request $request): Response
    {
        $targetDate = (string)$request->input('target_date', $this->context->save()['current_date']);
        try {
            $result = $this->calendar->advanceUntil($this->context, $targetDate);
            $message = sprintf('%d jour(s), %d match(s) du monde, %d entraînement(s), %d opération(s) de mercato.', $result['days'], $result['matches'], $result['training'], $result['transfers']);
            if ($result['awaiting_match'] > 0) $message .= ' Arrêt au jour de votre match : préparez puis jouez la rencontre.';
            $this->session->flash('success', $message);
        } catch (Throwable $exception) {
            $this->session->flash('error', $this->exceptionMessage($exception));
        }
        return $this->redirect($this->saveUrl('calendar?view=month&date=' . rawurlencode($targetDate)));
    }

    public function nextMatch(Request $request): Response
    {
        try {
            $result = $this->calendar->advanceToNextMatch($this->context);
            if ((int)$result['awaiting_match'] > 0) {
                $this->session->flash('success', 'Le monde est à jour. Votre rencontre est prête.');
                return $this->redirect($this->saveUrl('matches/' . (int)$result['awaiting_match']));
            }
            $this->session->flash('success', sprintf('%d jour(s) écoulé(s) et %d match(s) simulé(s).', $result['days'], $result['matches']));
        } catch (Throwable $exception) {
            $this->session->flash('error', $this->exceptionMessage($exception));
        }
        return $this->redirect($this->saveUrl('calendar'));
    }

    public function standings(Request $request): Response
    {
        $leagueId = (int)$request->query('league', (int)$this->context->club()['league_id']);
        $table = $this->league->table($this->context->saveId(), (int)$this->context->save()['current_season_id'], $leagueId);
        return $this->gameView('league::standings', array_merge($this->leagueData($leagueId), ['title'=>'Classement', 'active'=>'standings', 'table'=>$table]));
    }

    private function leagueData(int $leagueId): array
    {
        $leagues = $this->database->select('SELECT * FROM leagues WHERE save_id=:save_id ORDER BY level', ['save_id'=>$this->context->saveId()]);
        $league = null;
        foreach ($leagues as $row) if ((int)$row['id'] === $leagueId) $league = $row;
        if ($league === null) throw new \RuntimeException('Division invalide.');
        return ['leagues'=>$leagues, 'league'=>$league];
    }
}

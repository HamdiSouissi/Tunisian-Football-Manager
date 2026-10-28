<?php

declare(strict_types=1);

use App\Core\Config;
use App\Engine\TacticalMatchEngine;
use App\Services\FixtureGenerator;
use App\Services\TacticService;

require dirname(__DIR__) . '/bootstrap/autoload.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$makeSquad = static function (int $offset): array {
    $positions = [...TacticService::FORMATIONS['4-3-3'], 'GK', 'DC', 'MC', 'MR', 'ST', 'DC', 'MC'];
    $players = [];
    foreach ($positions as $index => $position) {
        $attributes = array_fill_keys(App\Services\PlayerFactory::ATTRIBUTES, 62);
        $players[] = [
            'id' => $offset + $index, 'first_name' => 'Joueur', 'last_name' => (string)($offset + $index),
            'primary_position' => $position, 'secondary_positions' => '[]', 'overall_rating' => 64,
            'form' => 60, 'morale' => 70, 'fatigue' => 0, ...$attributes,
        ];
    }
    return $players;
};

$makeTactic = static function (array $players): array {
    $lineup = [];
    foreach (TacticService::FORMATIONS['4-3-3'] as $slot => $position) {
        $lineup[] = ['player_id' => (int)$players[$slot]['id'], 'slot' => $slot, 'position' => $position, 'x' => 50, 'y' => 50, 'instruction' => 'normal', 'role' => 'support'];
    }
    return [
        'formation' => '4-3-3', 'mentality' => 'balanced', 'playing_style' => 'mixed', 'pressing' => 'medium',
        'defensive_line' => 'normal', 'tempo' => 'normal', 'width' => 'normal', 'lineup' => $lineup,
        'substitutes' => array_map(static fn ($p) => ['player_id' => (int)$p['id']], array_slice($players, 11)),
        'captain_player_id' => (int)$players[1]['id'], 'penalty_taker_id' => (int)$players[10]['id'], 'free_kick_taker_id' => (int)$players[6]['id'],
    ];
};

try {
    $config = new Config(dirname(__DIR__) . '/config');
    $engine = new TacticalMatchEngine($config);
    $home = $makeSquad(1000);
    $away = $makeSquad(2000);
    $homeTactic = $makeTactic($home);
    $awayTactic = $makeTactic($away);

    $baseline = $engine->initialize($home, $away, $homeTactic, $awayTactic);
    $assert(count($baseline['spatial']['players']['home']) === 11 && count($baseline['spatial']['players']['away']) === 11, 'Le moteur spatial doit positionner les 22 titulaires.');
    $assert(isset($baseline['weather']['type'], $baseline['spatial']['ball']['owner_id']), 'La météo et le possesseur initial doivent être persistés.');

    $spatialProperty = (new ReflectionClass($engine))->getProperty('spatial');
    $spatialProperty->setAccessible(true);
    $spatial = $spatialProperty->getValue($engine);
    $spatialReflection = new ReflectionClass($spatial);
    $offsideMethod = $spatialReflection->getMethod('isOffside');
    $offsideMethod->setAccessible(true);
    $offsideState = $baseline;
    foreach ($offsideState['spatial']['players']['away'] as $playerId => &$position) $position = ['x'=>60.0,'y'=>34.0];
    unset($position);
    $offsideState['spatial']['players']['away'][2000] = ['x'=>2.0,'y'=>34.0];
    $offsideState['spatial']['players']['away'][2001] = ['x'=>70.0,'y'=>34.0];
    $offsideState['spatial']['players']['away'][2002] = ['x'=>70.0,'y'=>34.0];
    $assert($offsideMethod->invoke($spatial, $offsideState, 'home', ['x'=>60.0,'y'=>34.0], ['x'=>80.0,'y'=>34.0]), 'Un receveur au-delà du deuxième dernier défenseur doit être hors-jeu.');
    $assert(!$offsideMethod->invoke($spatial, $offsideState, 'home', ['x'=>60.0,'y'=>34.0], ['x'=>65.0,'y'=>34.0]), 'Un receveur couvert ne doit pas être hors-jeu.');
    $completeMethod = $spatialReflection->getMethod('completeBallAction');
    $completeMethod->setAccessible(true);
    $offsideState['spatial']['last_pass'] = ['offside'=>true,'target_id'=>1005];
    $offsideState = $completeMethod->invoke($spatial, $offsideState, ['kind'=>'long_pass','target_side'=>'home','previous_side'=>'home','target_id'=>1005,'previous_owner_id'=>1004]);
    $assert($offsideState['stats']['home']['offsides'] === 1 && $offsideState['spatial']['restart']['reason'] === 'free_kick', 'L’hors-jeu doit donner un coup franc à la défense.');
    $throwState = $baseline;
    $throwState['spatial']['ball']['y'] = -1.0;
    $throwState = $completeMethod->invoke($spatial, $throwState, ['kind'=>'pass','target_side'=>'home','previous_side'=>'home','target_id'=>1005,'previous_owner_id'=>1004]);
    $assert($throwState['spatial']['restart']['reason'] === 'throw_in' && $throwState['spatial']['restart']['side'] === 'away' && $throwState['new_events'][0]['type'] === 'throw_in', 'Une sortie latérale doit produire une touche pour l’adversaire.');
    $shootMethod = $spatialReflection->getMethod('shoot');
    $shootMethod->setAccessible(true);
    $penaltyState = $shootMethod->invoke($spatial, $baseline, 'home', (int)$homeTactic['penalty_taker_id'], 11.0, true);
    $penaltyEvent = $penaltyState['new_events'][0];
    $assert(in_array($penaltyEvent['type'], ['penalty_goal','penalty_miss'], true) && $penaltyState['stats']['home']['shots'] === 1 && $penaltyState['spatial']['ball']['kind'] === 'penalty', 'Le penalty doit être une action spatiale comptabilisée.');

    $legacyState = $baseline;
    unset($legacyState['spatial'], $legacyState['weather'], $legacyState['player_ratings'], $legacyState['player_minutes'], $legacyState['experience_gained']);
    $legacyState['stats'] = ['home'=>['possession'=>0,'shots'=>0], 'away'=>['possession'=>0,'shots'=>0]];
    $legacyState = $engine->advance($legacyState);
    $assert($legacyState['minute'] === 1 && count($legacyState['spatial']['timeline']) === 10 && isset($legacyState['stats']['home']['passes'], $legacyState['player_ratings']['home'], $legacyState['weather']['type']), 'Un direct créé avant la refonte doit être hydraté puis lu sans erreur.');

    $attacking = $homeTactic;
    foreach ($attacking['lineup'] as &$entry) {
        $entry['instruction'] = 'attack';
        $entry['role'] = 'playmaker';
    }
    unset($entry);
    $attackingState = $engine->initialize($home, $away, $attacking, $awayTactic);
    $assert($attackingState['sides']['home']['base_ratings']['attack'] > $baseline['sides']['home']['base_ratings']['attack'], 'Les rôles offensifs doivent augmenter la force d’attaque.');
    $assert($attackingState['sides']['home']['base_ratings']['midfield'] > $baseline['sides']['home']['base_ratings']['midfield'], 'Les meneurs doivent influencer le milieu.');

    $state = $baseline;
    $allEvents = [];
    for ($i = 0; $i < 5; $i++) {
        $state = $engine->advance($state);
        $allEvents = [...$allEvents, ...$state['new_events']];
    }
    $assert(count($state['spatial']['timeline']) === 10, 'Chaque minute doit produire dix étapes spatiales de 100 ms.');
    foreach ($state['spatial']['timeline'] as $frame) {
        $assert(count($frame['players']['home']) >= 10 && count($frame['players']['away']) >= 10 && count($frame['players']['home']) + count($frame['players']['away']) >= 20, 'Une frame doit conserver tous les joueurs non expulsés.');
        $assert(count($frame['ball']) === 5, 'La trajectoire du ballon est incomplète.');
    }
    $out = (int)$homeTactic['lineup'][0]['player_id'];
    $in = (int)$homeTactic['substitutes'][0]['player_id'];
    $state = $engine->substitute($state, 'home', $out, $in);
    $assert(isset($state['sides']['home']['lineup'][$in]), 'Le remplaçant doit entrer dans le onze.');
    $assert(!isset($state['sides']['home']['lineup'][$out]), 'Le joueur remplacé doit quitter le onze.');
    $assert($state['sides']['home']['substitutions'] === 1, 'Le compteur de remplacements est incorrect.');

    $liveTactic = $state['sides']['home']['tactic'];
    $tacticDatabase = new App\Database\Database([]);
    $tacticTools = new TacticService($tacticDatabase, new App\Models\PlayerRepository($tacticDatabase));
    $beforeIds = array_column($liveTactic['lineup'], 'player_id');
    $liveTactic['lineup'] = $tacticTools->remapLineup($liveTactic['lineup'], '4-4-2');
    $liveTactic['formation'] = '4-4-2';
    $liveTactic['pressing'] = 'high';
    $liveTactic['mentality'] = 'attacking';
    $assert($beforeIds === array_column($liveTactic['lineup'], 'player_id'), 'La formation ne doit pas remplacer les joueurs présents sur le terrain.');
    $assert(TacticService::FORMATIONS['4-4-2'] === array_column($liveTactic['lineup'], 'position'), 'Les postes doivent correspondre à la nouvelle formation.');
    $preservedId = (int)$liveTactic['lineup'][1]['player_id'];
    $state['sides']['home']['energy'] = 73.5;
    $state['sides']['home']['lineup'][$preservedId]['cards'] = 1;
    $state = $engine->changeTactic($state, 'home', $liveTactic);
    $assert($state['sides']['home']['tactic']['pressing'] === 'high', 'Le changement tactique en direct n’est pas appliqué.');
    $assert($state['sides']['home']['tactic']['formation'] === '4-4-2', 'La formation en direct n’est pas appliquée.');
    $assert((float)$state['sides']['home']['energy'] === 73.5, 'Un changement tactique ne doit pas restaurer l’énergie.');
    $assert((int)$state['sides']['home']['lineup'][$preservedId]['cards'] === 1, 'Un changement tactique ne doit pas effacer les cartons.');
    $controllerReflection = new ReflectionClass(Modules\Match\Controllers\MatchController::class);
    $payloadMethod = $controllerReflection->getMethod('payload');
    $payloadMethod->setAccessible(true);
    $payload = $payloadMethod->invoke($controllerReflection->newInstanceWithoutConstructor(), $state);
    $assert($payload['sides']['home']['tactic']['formation'] === '4-4-2', 'Le payload du direct doit exposer la formation à jour.');
    $assert(count($payload['sides']['home']['lineup']) === 11 && count($payload['sides']['home']['substitutes']) === 6, 'Le payload doit synchroniser le onze et le banc sans rechargement.');
    $assert(count($payload['visual']['frames']) === 8 && isset($payload['visual']['weather']['type']) && count($payload['visual']['players']) >= 22, 'Le payload doit exposer la transition tactique, la météo et les métadonnées visuelles.');
    $assert(isset($payload['display_minute'], $payload['full_time']) && $payload['full_time'] === 90 + (int)$state['added_time']['second'], 'Le payload doit exposer le chronomètre avec temps additionnel.');

    $halftimeSeen = false;
    $guard = 0;
    while ($state['status'] !== 'finished' && $guard++ < 110) {
        $state = $engine->advance($state);
        $allEvents = [...$allEvents, ...$state['new_events']];
        if ($state['status'] === 'halftime') {
            $halftimeSeen = true;
            $assert((int)$state['minute'] === 45 + (int)$state['added_time']['first'], 'La mi-temps doit inclure le temps additionnel de la première période.');
            $state['status'] = 'live';
            $state['new_events'] = [];
        }
    }
    $assert($halftimeSeen, 'La mi-temps n’a pas été déclenchée.');
    $expectedFullTime = 90 + (int)$state['added_time']['second'];
    $assert($state['status'] === 'finished' && $state['minute'] === $expectedFullTime, 'Le match doit inclure le temps additionnel de la seconde période.');
    $assert($state['stats']['home']['possession'] + $state['stats']['away']['possession'] === $expectedFullTime, 'La possession doit comptabiliser chaque minute.');
    $assert(isset($state['man_of_the_match']['id'], $state['player_ratings']['home']), 'Les notes et l’homme du match doivent être disponibles au coup de sifflet final.');
    $assert($state['stats']['home']['passes'] >= $state['stats']['home']['passes_completed'], 'Les passes réussies ne peuvent pas dépasser les passes tentées.');
    $goals = array_filter($allEvents, static fn ($event) => in_array($event['type'], ['goal', 'penalty_goal'], true));
    $assert(count($goals) === $state['home_score'] + $state['away_score'], 'Le score doit correspondre aux événements de but.');

    $fixtures = (new FixtureGenerator())->generate(range(1, 16), new DateTimeImmutable('2026-08-01'));
    $assert(count($fixtures) === 240, 'Une division de 16 clubs doit produire 240 rencontres aller-retour.');
    $appearances = array_fill(1, 16, 0);
    $pairings = [];
    foreach ($fixtures as $fixture) {
        $assert($fixture['home'] !== $fixture['away'], 'Un club ne peut pas jouer contre lui-même.');
        $appearances[$fixture['home']]++;
        $appearances[$fixture['away']]++;
        $key = $fixture['leg'] . ':' . min($fixture['home'], $fixture['away']) . ':' . max($fixture['home'], $fixture['away']);
        $assert(!isset($pairings[$key]), 'Confrontation dupliquée dans la même phase.');
        $pairings[$key] = true;
    }
    foreach ($appearances as $count) {
        $assert($count === 30, 'Chaque club doit jouer 30 matchs.');
    }

    $calendarEngine = new App\Engine\CalendarEngine();
    $calendarFixtures = [['scheduled_at'=>'2026-08-15 15:00:00','home_club_id'=>1,'away_club_id'=>2,'home_short'=>'DOM','away_short'=>'EXT','matchday'=>1,'status'=>'scheduled']];
    $month = $calendarEngine->month('2026-08-10', $calendarFixtures, 1, '2026-08-10');
    $assert(count($month['cells']) === 42 && $month['previous'] === '2026-07-01' && $month['next'] === '2026-09-01', 'La navigation mensuelle doit produire une grille complète.');
    $matchCell = array_values(array_filter($month['cells'], static fn (array $cell): bool => $cell['date'] === '2026-08-15'))[0];
    $assert(count($matchCell['fixtures']) === 1 && $matchCell['fixtures'][0]['is_own'] === true, 'Le calendrier doit identifier les rencontres du club.');
    $week = $calendarEngine->week('2026-08-15', $calendarFixtures, 2, '2026-08-10');
    $assert(count($week['cells']) === 7 && $week['start'] === '2026-08-10' && $week['end'] === '2026-08-16', 'La vue hebdomadaire doit couvrir du lundi au dimanche.');

    $database = new App\Database\Database([]);
    $ai = new App\Services\ClubAIManager($database, $config);
    $target = ['market_value' => 100000, 'overall_rating' => 70, 'release_clause' => null];
    $offer = ['offer_type' => 'purchase', 'amount' => 120000];
    $assert($ai->evaluateTransferOffer($offer, $target, 'seller')['decision'] === 'accept', 'Une offre suffisante doit être acceptée par l’IA.');
    $offer['amount'] = 50000;
    $assert($ai->evaluateTransferOffer($offer, $target, 'seller')['decision'] === 'reject', 'Une offre trop faible doit être refusée par l’IA.');
    $offer['amount'] = 100000;
    $assert($ai->evaluateTransferOffer($offer, $target, 'seller')['decision'] === 'counter', 'Une offre intermédiaire doit produire une contre-offre.');
    $transfers = new App\Services\TransferService($database, new App\Models\PlayerRepository($database), $ai, new App\Events\EventDispatcher(), new App\Services\MessageService($database), $config);
    $assert($transfers->isWindowOpen('2026-07-15'), 'La fenêtre d’été doit être ouverte.');
    $assert($transfers->isWindowOpen('2027-01-15'), 'La fenêtre d’hiver doit être ouverte.');
    $assert(!$transfers->isWindowOpen('2026-10-15'), 'Le marché doit être fermé hors fenêtre.');

    echo "Tests moteur, calendrier et IA : OK\n";
} catch (Throwable $exception) {
    echo 'ÉCHEC : ' . $exception->getMessage() . "\n";
    exit(1);
}

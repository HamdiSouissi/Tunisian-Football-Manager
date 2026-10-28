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
    $out = (int)$homeTactic['lineup'][0]['player_id'];
    $in = (int)$homeTactic['substitutes'][0]['player_id'];
    $state = $engine->substitute($state, 'home', $out, $in);
    $assert(isset($state['sides']['home']['lineup'][$in]), 'Le remplaçant doit entrer dans le onze.');
    $assert(!isset($state['sides']['home']['lineup'][$out]), 'Le joueur remplacé doit quitter le onze.');
    $assert($state['sides']['home']['substitutions'] === 1, 'Le compteur de remplacements est incorrect.');

    $liveTactic = $state['sides']['home']['tactic'];
    $liveTactic['pressing'] = 'high';
    $liveTactic['mentality'] = 'attacking';
    $state = $engine->changeTactic($state, 'home', $liveTactic);
    $assert($state['sides']['home']['tactic']['pressing'] === 'high', 'Le changement tactique en direct n’est pas appliqué.');

    $halftimeSeen = false;
    $guard = 0;
    while ($state['status'] !== 'finished' && $guard++ < 100) {
        $state = $engine->advance($state);
        $allEvents = [...$allEvents, ...$state['new_events']];
        if ($state['status'] === 'halftime') {
            $halftimeSeen = true;
            $assert((int)$state['minute'] === 45, 'La mi-temps doit survenir à la 45e minute.');
            $state['status'] = 'live';
            $state['new_events'] = [];
        }
    }
    $assert($halftimeSeen, 'La mi-temps n’a pas été déclenchée.');
    $assert($state['status'] === 'finished' && $state['minute'] === 90, 'Le match doit se terminer à la 90e minute.');
    $assert($state['stats']['home']['possession'] + $state['stats']['away']['possession'] === 90, 'La possession doit comptabiliser chaque minute.');
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

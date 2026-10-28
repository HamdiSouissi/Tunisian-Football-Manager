<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use RuntimeException;

final class FixtureGenerator
{
    /** Génère un round-robin aller-retour par rotation circulaire. */
    public function generate(array $clubIds, DateTimeImmutable $firstMatchDate): array
    {
        $count = count($clubIds);
        if ($count < 2 || $count % 2 !== 0) {
            throw new RuntimeException('Le calendrier exige un nombre pair de clubs (minimum 2).');
        }
        $teams = array_values($clubIds);
        $rounds = $count - 1;
        $fixtures = [];

        for ($round = 0; $round < $rounds; $round++) {
            for ($index = 0; $index < $count / 2; $index++) {
                $home = $teams[$index];
                $away = $teams[$count - 1 - $index];
                if (($round + $index) % 2 === 1) {
                    [$home, $away] = [$away, $home];
                }
                $matchday = $round + 1;
                $fixtures[] = ['home' => $home, 'away' => $away, 'matchday' => $matchday, 'leg' => 'first', 'date' => $this->dateFor($firstMatchDate, $matchday)];
            }
            $fixed = array_shift($teams);
            $last = array_pop($teams);
            array_unshift($teams, $last);
            array_unshift($teams, $fixed);
        }

        $firstLeg = $fixtures;
        foreach ($firstLeg as $fixture) {
            $matchday = $fixture['matchday'] + $rounds;
            $fixtures[] = ['home' => $fixture['away'], 'away' => $fixture['home'], 'matchday' => $matchday, 'leg' => 'second', 'date' => $this->dateFor($firstMatchDate, $matchday)];
        }
        return $fixtures;
    }

    private function dateFor(DateTimeImmutable $firstMatchDate, int $matchday): DateTimeImmutable
    {
        // Une semaine de respiration toutes les cinq journées étale la saison jusqu'au printemps.
        $weeks = ($matchday - 1) + intdiv($matchday - 1, 5);
        return $firstMatchDate->modify('+' . $weeks . ' weeks')->setTime(15, 0);
    }
}

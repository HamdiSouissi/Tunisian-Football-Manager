<?php

declare(strict_types=1);

namespace App\Contracts;

interface MatchEngineInterface
{
    public function initialize(array $homePlayers, array $awayPlayers, array $homeTactic, array $awayTactic, array $options = []): array;
    public function advance(array $state, bool $ignoreHalftime = false): array;
    public function substitute(array $state, string $side, int $outPlayerId, int $inPlayerId): array;
    public function changeTactic(array $state, string $side, array $tactic): array;
}

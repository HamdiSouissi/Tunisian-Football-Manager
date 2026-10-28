<?php

declare(strict_types=1);

namespace App\Engine;

/** Commentaires factuels rattachés aux événements réellement produits par le moteur. */
final class CommentaryEngine
{
    public function event(int $minute, string $type, ?string $side, string $description, ?int $playerId = null, ?int $relatedId = null, array $data = []): array
    {
        return ['minute' => $minute, 'type' => $type, 'side' => $side, 'description' => $description, 'player_id' => $playerId, 'related_player_id' => $relatedId, 'data' => $data];
    }

    public function pass(array $state, string $side, int $fromId, int $toId, string $kind): ?array
    {
        if ((int)($state['spatial']['commentary_minute'] ?? -1) === (int)$state['minute'] && (int)($state['spatial']['commentary_count'] ?? 0) >= 1) {
            return null;
        }
        $from = $this->name($state, $side, $fromId);
        $to = $this->name($state, $side, $toId);
        $description = match ($kind) {
            'cross' => sprintf('%s centre vers %s.', $from, $to),
            'long_pass' => sprintf('%s trouve %s dans la profondeur.', $from, $to),
            default => sprintf('%s adresse une passe à %s.', $from, $to),
        };
        return $this->event((int)$state['minute'], $kind, $side, $description, $fromId, $toId);
    }

    public function interception(array $state, string $side, int $playerId, int $previousOwnerId): array
    {
        return $this->event((int)$state['minute'], 'interception', $side, $this->name($state, $side, $playerId) . ' intercepte le ballon.', $playerId, $previousOwnerId);
    }

    public function dribble(array $state, string $side, int $playerId, bool $success): array
    {
        $name = $this->name($state, $side, $playerId);
        return $this->event((int)$state['minute'], $success ? 'dribble' : 'tackle', $success ? $side : ($side === 'home' ? 'away' : 'home'), $success ? $name . ' élimine son adversaire.' : $name . ' perd son duel.', $playerId);
    }

    public function name(array $state, string $side, int $playerId): string
    {
        $player = $state['sides'][$side]['pool'][$playerId] ?? [];
        return trim((string)($player['first_name'] ?? '') . ' ' . (string)($player['last_name'] ?? '')) ?: 'Joueur';
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class ClubCatalog
{
    public function __construct(private readonly string $dataPath)
    {
    }

    public function leagues(): array
    {
        $json = file_get_contents($this->dataPath . '/clubs.json');
        if ($json === false) {
            throw new RuntimeException('Catalogue des clubs introuvable.');
        }
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    }

    public function clubs(?int $level = null): array
    {
        $clubs = [];
        foreach ($this->leagues() as $league) {
            if ($level !== null && (int) $league['level'] !== $level) {
                continue;
            }
            foreach ($league['clubs'] as $club) {
                $club['division'] = (int) $league['level'];
                $clubs[] = $club;
            }
        }
        return $clubs;
    }

    public function find(string $slug, int $level): array
    {
        foreach ($this->clubs($level) as $club) {
            if ($club['slug'] === $slug) {
                return $club;
            }
        }
        throw new RuntimeException('Club invalide pour la division choisie.');
    }
}

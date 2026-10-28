<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Session\Session;
use RuntimeException;

/** Contexte courant, toujours filtré par utilisateur et sauvegarde. */
final class GameContext
{
    private ?array $save = null;
    private ?array $club = null;
    private ?int $unread = null;

    public function __construct(private readonly Database $database, private readonly Session $session)
    {
    }

    public function load(int $saveId): void
    {
        $userId = (int) $this->session->get('user_id');
        $save = $this->database->selectOne(
            'SELECT * FROM saves WHERE id = :save_id AND user_id = :user_id LIMIT 1',
            ['save_id' => $saveId, 'user_id' => $userId]
        );
        if ($save === null) {
            throw new RuntimeException('Sauvegarde introuvable ou accès refusé.');
        }
        $club = $this->database->selectOne(
            'SELECT * FROM clubs WHERE id = :club_id AND save_id = :save_id LIMIT 1',
            ['club_id' => (int) $save['club_id'], 'save_id' => $saveId]
        );
        if ($club === null) {
            throw new RuntimeException('Club de la sauvegarde introuvable.');
        }
        $this->save = $save;
        $this->club = $club;
        $this->unread = null;
    }

    public function save(): array
    {
        return $this->save ?? throw new RuntimeException('Contexte de sauvegarde non initialisé.');
    }

    public function club(): array
    {
        return $this->club ?? throw new RuntimeException('Contexte de club non initialisé.');
    }

    public function saveId(): int
    {
        return (int) $this->save()['id'];
    }

    public function clubId(): int
    {
        return (int) $this->club()['id'];
    }

    public function userId(): int
    {
        return (int) $this->save()['user_id'];
    }

    public function refresh(): void
    {
        $this->load($this->saveId());
    }

    public function unreadMessages(): int
    {
        if ($this->unread === null) {
            $row = $this->database->selectOne(
                'SELECT COUNT(*) AS total FROM messages WHERE save_id = :save_id AND user_id = :user_id AND is_read = 0',
                ['save_id' => $this->saveId(), 'user_id' => $this->userId()]
            );
            $this->unread = (int) ($row['total'] ?? 0);
        }
        return $this->unread;
    }
}

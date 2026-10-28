<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;

final class MessageService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function send(int $saveId, int $userId, ?int $clubId, string $subject, string $body, string $category = 'general', ?string $actionUrl = null): void
    {
        $this->database->insert(
            'INSERT INTO messages (save_id, user_id, club_id, subject, body, category, action_url)
             VALUES (:save_id, :user_id, :club_id, :subject, :body, :category, :action_url)',
            ['save_id' => $saveId, 'user_id' => $userId, 'club_id' => $clubId, 'subject' => $subject, 'body' => $body, 'category' => $category, 'action_url' => $actionUrl]
        );
    }

    public function inbox(GameContext $context): array
    {
        return $this->database->select(
            'SELECT * FROM messages WHERE save_id = :save_id AND user_id = :user_id ORDER BY created_at DESC, id DESC LIMIT 250',
            ['save_id' => $context->saveId(), 'user_id' => $context->userId()]
        );
    }

    public function markRead(GameContext $context, int $messageId): void
    {
        $this->database->execute(
            'UPDATE messages SET is_read = 1, read_at = NOW() WHERE id = :id AND save_id = :save_id AND user_id = :user_id',
            ['id' => $messageId, 'save_id' => $context->saveId(), 'user_id' => $context->userId()]
        );
    }
}

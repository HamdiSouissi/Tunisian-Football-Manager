<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Database;
use App\Security\ValidationException;
use App\Session\Session;
use RuntimeException;

final class AuthService
{
    public function __construct(private readonly Database $database, private readonly Session $session)
    {
    }

    public function register(string $name, string $email, string $password, string $confirmation): int
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        $errors = [];
        $nameLength = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
        if ($nameLength < 2 || $nameLength > 100) {
            $errors['name'] = 'Le nom doit contenir entre 2 et 100 caractères.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) {
            $errors['email'] = 'Adresse e-mail invalide.';
        }
        if (strlen($password) < 10) {
            $errors['password'] = 'Le mot de passe doit contenir au moins 10 caractères.';
        }
        if ($password !== $confirmation) {
            $errors['password_confirmation'] = 'Les mots de passe ne correspondent pas.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($this->database->selectOne('SELECT id FROM users WHERE email = :email', ['email' => $email]) !== null) {
            throw new ValidationException(['email' => 'Cette adresse est déjà utilisée.']);
        }

        $id = (int) $this->database->insert(
            'INSERT INTO users (name, email, password_hash) VALUES (:name, :email, :password_hash)',
            ['name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]
        );
        $this->session->regenerate();
        $this->session->set('user_id', $id);
        return $id;
    }

    public function attempt(string $email, string $password): bool
    {
        $email = strtolower(trim($email));
        $user = $this->database->selectOne('SELECT * FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
        if ($user === null || !(bool) $user['is_active'] || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->database->execute('UPDATE users SET last_login_at = NOW() WHERE id = :id', ['id' => (int) $user['id']]);
        return true;
    }

    public function logout(): void
    {
        $this->session->invalidate();
    }

    public function check(): bool
    {
        return is_int($this->session->get('user_id')) || ctype_digit((string) $this->session->get('user_id', ''));
    }

    public function userId(): int
    {
        if (!$this->check()) {
            throw new RuntimeException('Aucun utilisateur connecté.');
        }
        return (int) $this->session->get('user_id');
    }

    public function user(): ?array
    {
        if (!$this->check()) {
            return null;
        }
        return $this->database->selectOne('SELECT id, name, email, last_login_at FROM users WHERE id = :id', ['id' => $this->userId()]);
    }
}

<?php

declare(strict_types=1);

namespace App\Session;

use RuntimeException;

/**
 * Encapsule les sessions PHP avec cookies restrictifs et régénération d'identifiant.
 */
final class Session
{
    /** @param array<string, mixed> $settings */
    public function __construct(private readonly array $settings)
    {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (headers_sent()) {
            throw new RuntimeException('Impossible de démarrer la session après l’envoi des en-têtes.');
        }

        $savePath = (string) ($this->settings['save_path'] ?? '');
        if ($savePath !== '') {
            if (!is_dir($savePath) && !@mkdir($savePath, 0775, true) && !is_dir($savePath)) {
                throw new RuntimeException(sprintf('Impossible de créer le répertoire de sessions "%s".', $savePath));
            }
            if (!is_writable($savePath)) {
                throw new RuntimeException(sprintf('Le répertoire de sessions "%s" n’est pas accessible en écriture.', $savePath));
            }
            session_save_path($savePath);
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.gc_maxlifetime', (string) ((int) ($this->settings['lifetime'] ?? 120) * 60));

        session_name((string) ($this->settings['name'] ?? 'tfm_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => (string) ($this->settings['path'] ?? '/'),
            'domain' => (string) ($this->settings['domain'] ?? ''),
            'secure' => (bool) ($this->settings['secure'] ?? false),
            'httponly' => (bool) ($this->settings['http_only'] ?? true),
            'samesite' => (string) ($this->settings['same_site'] ?? 'Lax'),
        ]);
        session_start();

        if (!isset($_SESSION['_meta']['created_at'])) {
            $_SESSION['_meta']['created_at'] = time();
        }
        $_SESSION['_meta']['last_activity'] = time();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function invalidate(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $_SESSION = [];
        $cookie = session_get_cookie_params();
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $cookie['path'],
                'domain' => $cookie['domain'],
                'secure' => $cookie['secure'],
                'httponly' => $cookie['httponly'],
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
    }

    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    public function pullFlash(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }
}

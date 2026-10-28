<?php

declare(strict_types=1);

namespace App\Security;

use App\Session\Session;

final class Csrf
{
    private const SESSION_KEY = '_csrf_token';

    public function __construct(private readonly Session $session)
    {
    }

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            $token = bin2hex(random_bytes(32));
            $this->session->set(self::SESSION_KEY, $token);
        }
        return $token;
    }

    public function validate(?string $token): bool
    {
        return is_string($token)
            && $token !== ''
            && hash_equals($this->token(), $token);
    }

    public function rotate(): void
    {
        $this->session->set(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}

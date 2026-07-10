<?php

declare(strict_types=1);

namespace App;

final class Csrf
{
    public function __construct(private readonly string $sessionKey)
    {
    }

    public function token(): string
    {
        if (!isset($_SESSION[$this->sessionKey])) {
            $_SESSION[$this->sessionKey] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION[$this->sessionKey];
    }

    public function validate(?string $token): bool
    {
        $stored = $_SESSION[$this->sessionKey] ?? '';
        return is_string($token) && is_string($stored) && $stored !== '' && hash_equals($stored, $token);
    }
}

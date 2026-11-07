<?php

declare(strict_types=1);

namespace App;

/**
 * Synchronizer-token CSRF protection that survives session loss.
 *
 * The token is kept in the session and mirrored into a long-lived, HttpOnly,
 * SameSite cookie. If the server-side session is garbage-collected while a
 * page sits open (e.g. a timer running for hours) and the user is silently
 * logged back in via the remember-me cookie, the token embedded in the open
 * page is still accepted because it is restored from the cookie instead of
 * being replaced by a brand-new one.
 *
 * The cookie is only consulted when the session holds no token, so an intact
 * session is always checked against its own token. The token is rotated on
 * interactive login and cleared on logout so it never carries over between
 * users of the same browser.
 */
final class Csrf
{
    public function __construct(
        private readonly string $sessionKey,
        private readonly string $cookieName = 'sprintask_csrf',
        private readonly int $cookieTtlSeconds = 30 * 24 * 3600,
        private readonly ?bool $secure = null
    ) {
    }

    public function token(): string
    {
        $token = $this->storedToken() ?? self::generate();
        $this->store($token);
        return $token;
    }

    public function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        $stored = $this->storedToken();
        if ($stored === null || !hash_equals($stored, $token)) {
            return false;
        }

        // Re-sync the session if it was lost and restored from the cookie.
        $_SESSION[$this->sessionKey] = $stored;
        return true;
    }

    /** Issue a brand-new token (call after an interactive login). */
    public function rotate(): string
    {
        $token = self::generate();
        $this->store($token);
        return $token;
    }

    /** Forget the token in both the session and the cookie (call on logout). */
    public function clear(): void
    {
        unset($_SESSION[$this->sessionKey]);
        $this->writeCookie('', time() - 3600);
        unset($_COOKIE[$this->cookieKey()]);
    }

    private function storedToken(): ?string
    {
        $session = $_SESSION[$this->sessionKey] ?? null;
        if (self::isWellFormed($session)) {
            return $session;
        }
        $cookie = $_COOKIE[$this->cookieKey()] ?? null;
        return self::isWellFormed($cookie) ? $cookie : null;
    }

    private function store(string $token): void
    {
        $_SESSION[$this->sessionKey] = $token;
        // Only send Set-Cookie when the browser doesn't already hold this value.
        if (($_COOKIE[$this->cookieKey()] ?? null) !== $token) {
            $this->writeCookie($token, time() + $this->cookieTtlSeconds);
            $_COOKIE[$this->cookieKey()] = $token;
        }
    }

    private function writeCookie(string $value, int $expires): void
    {
        if (headers_sent() || PHP_SAPI === 'cli') {
            return;
        }
        setcookie($this->cookieKey(), $value, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function cookieKey(): string
    {
        // "__Host-" cookies cannot be planted by sibling subdomains or over plain HTTP.
        return $this->isSecure() ? '__Host-' . $this->cookieName : $this->cookieName;
    }

    private function isSecure(): bool
    {
        return $this->secure ?? Util::isHttps($_SERVER);
    }

    private static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    private static function isWellFormed(mixed $value): bool
    {
        return is_string($value) && strlen($value) === 64 && ctype_xdigit($value);
    }
}

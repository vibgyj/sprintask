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
 * A cross-site attacker can neither read the HttpOnly cookie nor the token in
 * the page, so they still cannot produce a matching form value.
 */
final class Csrf
{
    private const COOKIE_TTL_SECONDS = 30 * 24 * 3600;

    public function __construct(
        private readonly string $sessionKey,
        private readonly string $cookieName = 'sprintask_csrf',
        private readonly ?bool $secure = null
    ) {
    }

    public function token(): string
    {
        $token = $this->storedToken();
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
        }

        $_SESSION[$this->sessionKey] = $token;
        $this->persistCookie($token);

        return $token;
    }

    public function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '') {
            return false;
        }

        foreach ($this->candidates() as $stored) {
            if (hash_equals($stored, $token)) {
                // Re-sync the session if it was lost and restored from the cookie.
                $_SESSION[$this->sessionKey] = $stored;
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function candidates(): array
    {
        $out = [];
        $session = $_SESSION[$this->sessionKey] ?? null;
        if (self::isWellFormed($session)) {
            $out[] = $session;
        }
        $cookie = $_COOKIE[$this->cookieKey()] ?? null;
        if (self::isWellFormed($cookie) && !in_array($cookie, $out, true)) {
            $out[] = $cookie;
        }
        return $out;
    }

    private function storedToken(): ?string
    {
        return $this->candidates()[0] ?? null;
    }

    private function persistCookie(string $token): void
    {
        $name = $this->cookieKey();
        if (headers_sent() || PHP_SAPI === 'cli') {
            $_COOKIE[$name] = $token;
            return;
        }

        // Refresh the cookie on every render so its lifetime slides with activity.
        setcookie($name, $token, [
            'expires' => time() + self::COOKIE_TTL_SECONDS,
            'path' => '/',
            'domain' => '',
            'secure' => $this->isSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $token;
    }

    private function cookieKey(): string
    {
        // "__Host-" cookies cannot be planted by sibling subdomains or over plain HTTP.
        return $this->isSecure() ? '__Host-' . $this->cookieName : $this->cookieName;
    }

    private function isSecure(): bool
    {
        if ($this->secure !== null) {
            return $this->secure;
        }
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    private static function isWellFormed(mixed $value): bool
    {
        return is_string($value) && strlen($value) === 64 && ctype_xdigit($value);
    }
}

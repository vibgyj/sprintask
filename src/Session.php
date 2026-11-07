<?php

declare(strict_types=1);

namespace App;

final class Session
{
    public function __construct(
        private readonly string $name,
        private readonly ?string $savePath = null,
        private readonly int $idleLifetimeSeconds = 0
    ) {
    }

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        // PHP's default gc_maxlifetime (24 min) wipes the session of a page
        // left open while a long timer runs, which used to break every form
        // on that page. Keep idle sessions much longer, in a private directory
        // so a shared host's /tmp session cleaner cannot purge them early.
        if ($this->idleLifetimeSeconds > 0) {
            ini_set('session.gc_maxlifetime', (string) $this->idleLifetimeSeconds);
        }
        if ($this->savePath !== null && $this->savePath !== '') {
            if (!is_dir($this->savePath)) {
                @mkdir($this->savePath, 0700, true);
            }
            if (!is_dir($this->savePath) || !is_writable($this->savePath)) {
                error_log(sprintf(
                    'Sprintask: session directory "%s" is missing or not writable; falling back to "%s", where idle sessions may be purged early.',
                    $this->savePath,
                    (string) session_save_path()
                ));
            } else {
                session_save_path($this->savePath);
                // Some distros disable PHP's own GC and rely on a cron job that
                // only knows the default directory, so enable it for ours.
                ini_set('session.gc_probability', '1');
                ini_set('session.gc_divisor', '100');
            }
        }

        $secure = Util::isHttps($_SERVER);

        session_name($this->name);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }
}

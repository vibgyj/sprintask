<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use App\Mail\Mailer;
use PDO;

final class AuthService
{
    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly array $security
    ) {
    }

    public function requestOtp(string $purpose, string $email, ?string $name, string $ip, string $ua): array
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'Invalid email.'];
        }

        $pdo = $this->db->pdo();
        $now = time();

        if ($this->isRateLimited($pdo, $email, $ip, $now)) {
            return ['ok' => false, 'error' => 'Too many OTP requests. Try again later.'];
        }

        $user = $this->findUserByEmail($email);
        if ($purpose === 'register' && $user) {
            return ['ok' => false, 'error' => 'Email already registered.'];
        }
        if ($purpose === 'login' && !$user) {
            return ['ok' => false, 'error' => 'No account exists for that email.'];
        }

        $cooldown = $this->activeCooldown($pdo, $email, $purpose, $now);
        if ($cooldown > 0) {
            return ['ok' => false, 'error' => 'Wait ' . $cooldown . ' seconds before requesting a new code.'];
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE otp_challenges SET consumed_at_utc = :now WHERE email = :email AND purpose = :purpose AND consumed_at_utc IS NULL AND expires_at_utc > :now');
            $stmt->execute([':now' => $now, ':email' => $email, ':purpose' => $purpose]);

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $expiresAt = $now + (int) $this->security['otp_ttl_seconds'];
            $resendAt = $now + (int) $this->security['otp_resend_cooldown_seconds'];

            $insert = $pdo->prepare('INSERT INTO otp_challenges (user_id, email, purpose, code_hash, expires_at_utc, consumed_at_utc, attempt_count, max_attempts, resend_available_at_utc, requested_ip, user_agent, created_at_utc) VALUES (:user_id, :email, :purpose, :code_hash, :expires, NULL, 0, :max_attempts, :resend_at, :ip, :ua, :created)');
            $insert->execute([
                ':user_id' => $user['id'] ?? null,
                ':email' => $email,
                ':purpose' => $purpose,
                ':code_hash' => $hash,
                ':expires' => $expiresAt,
                ':max_attempts' => (int) $this->security['otp_max_attempts'],
                ':resend_at' => $resendAt,
                ':ip' => $ip,
                ':ua' => substr($ua, 0, 255),
                ':created' => $now,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $subject = 'Your Sprintask verification code';
        $body = "Your one-time code is: {$code}\n\nThis code expires in 10 minutes. If you did not request this, you can ignore this message.";
        $sent = $this->mailer->send($email, $subject, $body);

        return ['ok' => $sent, 'error' => $sent ? null : 'Could not send OTP.', 'code' => $code];
    }

    public function verifyOtp(string $purpose, string $email, string $code, ?string $registerName = null): array
    {
        $email = strtolower(trim($email));
        $code = trim($code);
        $now = time();

        if (!preg_match('/^\d{6}$/', $code)) {
            return ['ok' => false, 'error' => 'Code must be 6 digits.'];
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM otp_challenges WHERE email = :email AND purpose = :purpose ORDER BY id DESC LIMIT 1');
        $stmt->execute([':email' => $email, ':purpose' => $purpose]);
        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge || $challenge['consumed_at_utc'] !== null || (int) $challenge['expires_at_utc'] <= $now) {
            return ['ok' => false, 'error' => 'Code is invalid or expired.'];
        }

        if ((int) $challenge['attempt_count'] >= (int) $challenge['max_attempts']) {
            return ['ok' => false, 'error' => 'Too many attempts. Request a new code.'];
        }

        if (!password_verify($code, $challenge['code_hash'])) {
            $attempts = (int) $challenge['attempt_count'] + 1;
            $consume = $attempts >= (int) $challenge['max_attempts'] ? $now : null;
            $upd = $pdo->prepare('UPDATE otp_challenges SET attempt_count = :attempts, consumed_at_utc = COALESCE(:consume, consumed_at_utc) WHERE id = :id');
            $upd->execute([':attempts' => $attempts, ':consume' => $consume, ':id' => $challenge['id']]);
            return ['ok' => false, 'error' => 'Code is invalid.'];
        }

        $pdo->beginTransaction();
        try {
            $mark = $pdo->prepare('UPDATE otp_challenges SET consumed_at_utc = :now WHERE id = :id');
            $mark->execute([':now' => $now, ':id' => $challenge['id']]);

            if ($purpose === 'register') {
                $name = trim((string) $registerName);
                if ($name === '') {
                    throw new \RuntimeException('Name is required.');
                }
                $create = $pdo->prepare('INSERT INTO users (email, name, timezone, theme, created_at_utc) VALUES (:email, :name, :timezone, :theme, :created)');
                $create->execute([
                    ':email' => $email,
                    ':name' => $name,
                    ':timezone' => 'UTC',
                    ':theme' => 'system',
                    ':created' => $now,
                ]);
                $userId = (int) $pdo->lastInsertId();
            } else {
                $user = $this->findUserByEmail($email);
                if (!$user) {
                    throw new \RuntimeException('User no longer exists.');
                }
                $userId = (int) $user['id'];
            }

            $pdo->commit();
            return ['ok' => true, 'user_id' => $userId];
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    public function findUserById(int $id): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Current session epoch for a user. Bumped whenever the user chooses to
     * "log out of other devices"; any session/remember-token stamped with an
     * older version is treated as invalid.
     */
    public function sessionVersion(int $userId): int
    {
        $stmt = $this->db->pdo()->prepare('SELECT session_version FROM user_security WHERE user_id = :id');
        $stmt->execute([':id' => $userId]);
        $version = $stmt->fetchColumn();
        return $version === false ? 0 : (int) $version;
    }

    /**
     * Finalizes a successful OTP login: optionally invalidates every other
     * device (session-version bump + wipe of remember-me tokens) and/or
     * issues a new 30-day "remember me" token for this device.
     *
     * @return array{session_version:int, remember: array{selector:string, validator:string, expires_at:int}|null}
     */
    public function completeLogin(int $userId, bool $rememberMe, bool $logoutOtherDevices, string $ip, string $ua): array
    {
        $pdo = $this->db->pdo();
        $now = time();

        $pdo->beginTransaction();
        try {
            $version = $this->sessionVersion($userId);

            if ($logoutOtherDevices) {
                $version++;
                $upsert = $pdo->prepare(
                    'INSERT INTO user_security (user_id, session_version) VALUES (:id, :v)
                     ON CONFLICT(user_id) DO UPDATE SET session_version = :v'
                );
                $upsert->execute([':id' => $userId, ':v' => $version]);
                $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute([':id' => $userId]);
            }

            $remember = null;
            if ($rememberMe) {
                $selector = bin2hex(random_bytes(9));
                $validator = bin2hex(random_bytes(33));
                $expiresAt = $now + (int) $this->security['remember_me_ttl_seconds'];

                $insert = $pdo->prepare(
                    'INSERT INTO remember_tokens (user_id, selector, validator_hash, session_version, expires_at_utc, created_at_utc, requested_ip, user_agent)
                     VALUES (:user_id, :selector, :hash, :version, :expires, :created, :ip, :ua)'
                );
                $insert->execute([
                    ':user_id' => $userId,
                    ':selector' => $selector,
                    ':hash' => hash('sha256', $validator),
                    ':version' => $version,
                    ':expires' => $expiresAt,
                    ':created' => $now,
                    ':ip' => $ip,
                    ':ua' => substr($ua, 0, 255),
                ]);

                $remember = ['selector' => $selector, 'validator' => $validator, 'expires_at' => $expiresAt];
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return ['session_version' => $version, 'remember' => $remember];
    }

    /**
     * Validates a "selector:validator" remember-me cookie value. On success,
     * rotates the token (new selector/validator) and returns login details;
     * on any failure the stale token row is removed and null is returned.
     *
     * @return array{user_id:int, session_version:int, selector:string, validator:string, expires_at:int}|null
     */
    public function loginFromRememberToken(string $cookieValue): ?array
    {
        if (!str_contains($cookieValue, ':')) {
            return null;
        }
        [$selector, $validator] = explode(':', $cookieValue, 2);
        if ($selector === '' || $validator === '') {
            return null;
        }

        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM remember_tokens WHERE selector = :selector');
        $stmt->execute([':selector' => $selector]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$token) {
            return null;
        }

        $now = time();
        $valid = (int) $token['expires_at_utc'] > $now
            && hash_equals((string) $token['validator_hash'], hash('sha256', $validator));

        if ($valid) {
            $user = $this->findUserById((int) $token['user_id']);
            $currentVersion = $user ? $this->sessionVersion((int) $user['id']) : null;
            $valid = $user !== null && (int) $token['session_version'] === $currentVersion;
        }

        if (!$valid) {
            $pdo->prepare('DELETE FROM remember_tokens WHERE id = :id')->execute([':id' => $token['id']]);
            return null;
        }

        $newSelector = bin2hex(random_bytes(9));
        $newValidator = bin2hex(random_bytes(33));
        $expiresAt = $now + (int) $this->security['remember_me_ttl_seconds'];

        $update = $pdo->prepare(
            'UPDATE remember_tokens SET selector = :sel, validator_hash = :hash, expires_at_utc = :exp WHERE id = :id'
        );
        $update->execute([
            ':sel' => $newSelector,
            ':hash' => hash('sha256', $newValidator),
            ':exp' => $expiresAt,
            ':id' => $token['id'],
        ]);

        return [
            'user_id' => (int) $token['user_id'],
            'session_version' => $currentVersion,
            'selector' => $newSelector,
            'validator' => $newValidator,
            'expires_at' => $expiresAt,
        ];
    }

    public function revokeRememberTokenBySelector(string $selector): void
    {
        $this->db->pdo()->prepare('DELETE FROM remember_tokens WHERE selector = :selector')->execute([':selector' => $selector]);
    }

    public function findUserByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function isRateLimited(PDO $pdo, string $email, string $ip, int $now): bool
    {
        $emailSince = $now - (int) $this->security['otp_email_window_seconds'];
        $ipSince = $now - (int) $this->security['otp_ip_window_seconds'];

        $emailQ = $pdo->prepare('SELECT COUNT(*) FROM otp_challenges WHERE email = :email AND created_at_utc >= :since');
        $emailQ->execute([':email' => $email, ':since' => $emailSince]);
        $emailCount = (int) $emailQ->fetchColumn();

        $ipQ = $pdo->prepare('SELECT COUNT(*) FROM otp_challenges WHERE requested_ip = :ip AND created_at_utc >= :since');
        $ipQ->execute([':ip' => $ip, ':since' => $ipSince]);
        $ipCount = (int) $ipQ->fetchColumn();

        return $emailCount >= (int) $this->security['otp_email_max_requests']
            || $ipCount >= (int) $this->security['otp_ip_max_requests'];
    }

    private function activeCooldown(PDO $pdo, string $email, string $purpose, int $now): int
    {
        $q = $pdo->prepare('SELECT resend_available_at_utc FROM otp_challenges WHERE email = :email AND purpose = :purpose AND consumed_at_utc IS NULL AND expires_at_utc > :now ORDER BY id DESC LIMIT 1');
        $q->execute([':email' => $email, ':purpose' => $purpose, ':now' => $now]);
        $resendAt = $q->fetchColumn();
        if ($resendAt === false) {
            return 0;
        }
        return max(0, (int) $resendAt - $now);
    }
}

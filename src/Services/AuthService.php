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

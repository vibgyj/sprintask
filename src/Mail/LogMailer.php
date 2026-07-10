<?php

declare(strict_types=1);

namespace App\Mail;

final class LogMailer implements Mailer
{
    public function __construct(private readonly string $logPath)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $dir = dirname($this->logPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $line = json_encode([
            'sent_at' => gmdate('c'),
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
        ], JSON_UNESCAPED_SLASHES);

        return file_put_contents($this->logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) !== false;
    }
}

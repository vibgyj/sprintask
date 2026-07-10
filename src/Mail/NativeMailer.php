<?php

declare(strict_types=1);

namespace App\Mail;

final class NativeMailer implements Mailer
{
    public function __construct(private readonly string $fromAddress, private readonly string $fromName)
    {
    }

    public function send(string $to, string $subject, string $body): bool
    {
        $safeTo = $this->stripNewlines($to);
        $safeSubject = $this->stripNewlines($subject);
        $fromAddress = $this->stripNewlines($this->fromAddress);
        $fromName = $this->stripNewlines($this->fromName);

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $fromName . ' <' . $fromAddress . '>',
            'Reply-To: ' . $fromAddress,
            'X-Mailer: PHP/' . phpversion(),
        ];

        return mail($safeTo, $safeSubject, $body, implode("\r\n", $headers));
    }

    private function stripNewlines(string $value): string
    {
        return preg_replace('/[\r\n]+/', ' ', trim($value)) ?? '';
    }
}

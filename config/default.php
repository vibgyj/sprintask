<?php

return [
    'app' => [
        'env' => getenv('APP_ENV') ?: 'production',
        'base_url' => getenv('APP_BASE_URL') ?: '',
        'timezone' => getenv('APP_TIMEZONE') ?: 'UTC',
    ],
    'db' => [
        'path' => __DIR__ . '/../var/data/app.sqlite',
    ],
    'security' => [
        'csrf_key' => '_csrf_token',
        'otp_ttl_seconds' => 600,
        'otp_max_attempts' => 5,
        'otp_resend_cooldown_seconds' => 60,
        'otp_email_window_seconds' => 3600,
        'otp_email_max_requests' => 5,
        'otp_ip_window_seconds' => 3600,
        'otp_ip_max_requests' => 20,
    ],
    'mail' => [
        'transport' => (getenv('APP_ENV') ?: 'production') === 'development' ? 'log' : 'mail',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'no-reply@example.com',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'Sprintask',
        'log_path' => __DIR__ . '/../var/log/mail.log',
    ],
    'session' => [
        'name' => 'sprintask_session',
    ],
];

<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$root = dirname(__DIR__);
$config = require $root . '/config/default.php';
if (is_file($root . '/config/local.php')) {
    $config = array_replace_recursive($config, require $root . '/config/local.php');
}

date_default_timezone_set($config['app']['timezone'] ?? 'UTC');

$session = new App\Session(
    $config['session']['name'] ?? 'sprintask_session',
    $config['session']['save_path'] ?? null,
    (int) ($config['session']['idle_lifetime_seconds'] ?? 0)
);
$session->start();

$database = new App\Database($config['db']['path']);
$database->migrate($root . '/database/schema.sql');

$mailer = ($config['mail']['transport'] ?? 'mail') === 'log'
    ? new App\Mail\LogMailer($config['mail']['log_path'])
    : new App\Mail\NativeMailer($config['mail']['from_address'], $config['mail']['from_name']);

return new App\Application($config, $database, $mailer, $root);

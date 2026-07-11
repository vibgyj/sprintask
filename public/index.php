<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap_resolver.php';

$bootstrap = sprintaskResolveBootstrapPath($_SERVER, __DIR__);
try {
    sprintaskRequireBootstrapPath($bootstrap);
} catch (RuntimeException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage() . "\n";
    exit(1);
}

$app = require $bootstrap;
$app->run();

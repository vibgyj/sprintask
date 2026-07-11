<?php

declare(strict_types=1);

if (!function_exists('sprintaskResolveAppRoot')) {
    function sprintaskResolveAppRoot(array $server, string $publicDir): string
    {
        $envRoot = getenv('SPRINTASK_ROOT');
        if (!is_string($envRoot) || trim($envRoot) === '') {
            $serverValue = $server['SPRINTASK_ROOT'] ?? null;
            $envRoot = is_string($serverValue) ? $serverValue : '';
        }

        $root = trim($envRoot) !== '' ? rtrim(trim($envRoot), '/\\') : dirname($publicDir);
        if ($root === '') {
            $root = DIRECTORY_SEPARATOR;
        }

        return $root;
    }

    function sprintaskResolveBootstrapPath(array $server, string $publicDir): string
    {
        $root = sprintaskResolveAppRoot($server, $publicDir);

        return $root . '/src/bootstrap.php';
    }

    function sprintaskRequireBootstrapPath(string $bootstrapPath): void
    {
        if (!is_file($bootstrapPath)) {
            throw new RuntimeException('Sprintask is misconfigured. Could not find src/bootstrap.php. Set SPRINTASK_ROOT to the private application directory that contains src/bootstrap.php.');
        }
    }
}

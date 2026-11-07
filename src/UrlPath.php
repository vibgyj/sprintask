<?php

declare(strict_types=1);

namespace App;

final class UrlPath
{
    public static function resolveBasePath(array $server, string $appBaseUrl = ''): string
    {
        $runtimeBasePath = self::basePathFromScript($server['SCRIPT_NAME'] ?? null);
        $configuredBasePath = self::basePathFromAppUrl($appBaseUrl);

        return $configuredBasePath ?? $runtimeBasePath;
    }

    public static function basePathFromScript(mixed $scriptName): string
    {
        if (!is_string($scriptName) || $scriptName === '') {
            return '';
        }

        $scriptPath = parse_url($scriptName, PHP_URL_PATH);
        if (!is_string($scriptPath) || $scriptPath === '') {
            return '';
        }

        return self::normalizePrefix(dirname($scriptPath));
    }

    public static function basePathFromAppUrl(string $appBaseUrl): ?string
    {
        $appBaseUrl = trim($appBaseUrl);
        if ($appBaseUrl === '') {
            return null;
        }

        $path = parse_url($appBaseUrl, PHP_URL_PATH);
        if ($path === false || !is_string($path)) {
            return null;
        }

        return self::normalizePrefix($path);
    }

    public static function stripBasePath(string $requestPath, string $basePath): string
    {
        $normalizedRequest = self::normalizeRequestPath($requestPath);
        $normalizedBasePath = self::normalizePrefix($basePath);

        if ($normalizedBasePath === '') {
            return $normalizedRequest;
        }

        if ($normalizedRequest === $normalizedBasePath) {
            return '/';
        }

        $prefix = $normalizedBasePath . '/';
        if (str_starts_with($normalizedRequest, $prefix)) {
            return substr($normalizedRequest, strlen($normalizedBasePath)) ?: '/';
        }

        return $normalizedRequest;
    }

    public static function isUnderBasePath(string $requestPath, string $basePath): bool
    {
        $normalizedBasePath = self::normalizePrefix($basePath);
        if ($normalizedBasePath === '') {
            return true;
        }
        $normalizedRequest = self::normalizeRequestPath($requestPath);
        return $normalizedRequest === $normalizedBasePath
            || str_starts_with($normalizedRequest, $normalizedBasePath . '/');
    }

    public static function appPath(string $path, string $basePath): string
    {
        $normalizedPath = self::normalizeRequestPath($path);
        $normalizedBasePath = self::normalizePrefix($basePath);
        if ($normalizedBasePath === '') {
            return $normalizedPath;
        }

        if ($normalizedPath === '/') {
            return $normalizedBasePath . '/';
        }

        return $normalizedBasePath . $normalizedPath;
    }

    private static function normalizeRequestPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/';
        }

        $path = '/' . ltrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    private static function normalizePrefix(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '' || $path === '/' || $path === '.') {
            return '';
        }

        return '/' . trim($path, '/');
    }
}

<?php

declare(strict_types=1);

namespace App;

use App\Services\ReportService;

/**
 * Builds app paths that carry page view state (filters, sort, paging), shared
 * by templates (links) and the controller (redirects after a POST), so both
 * always agree on parameter names, allowed values and defaults.
 */
final class ViewState
{
    /** Settings grid params => allowed values; the first value is the default. */
    public const SETTINGS_GRID = [
        'sort' => ['category', 'task'],
        'dir' => ['asc', 'desc'],
        'per' => ['10', '50', '100'],
    ];

    /** Dashboard path for a history range (the default range has no query). */
    public static function historyPath(mixed $range): string
    {
        $range = ReportService::normalizeRange($range);
        return $range === ReportService::DEFAULT_HISTORY_RANGE ? '/' : '/?range=' . rawurlencode($range);
    }

    /**
     * Settings path keeping valid grid state from $input. Keys are read with
     * $prefix (forms post them as grid_sort, grid_dir, ...); defaults and
     * invalid values are dropped.
     */
    public static function settingsPath(array $input, string $prefix = ''): string
    {
        $query = [];
        foreach (self::SETTINGS_GRID as $key => $allowed) {
            $value = (string) ($input[$prefix . $key] ?? '');
            if ($value !== $allowed[0] && in_array($value, $allowed, true)) {
                $query[$key] = $value;
            }
        }
        $page = (int) ($input[$prefix . 'page'] ?? 0);
        if ($page > 1) {
            $query['page'] = (string) $page;
        }
        return '/settings' . ($query ? '?' . http_build_query($query) : '');
    }
}

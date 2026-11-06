<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Builds category > task > entry trees of tracked time, clipped to a window.
 *
 * Ranges ("24h", "7d", "30d") are used by both the dashboard history view
 * (a rolling window ending now) and the reports page (a window starting at a
 * user-chosen local date/time, optionally split into aggregation buckets).
 */
final class ReportService
{
    /** Range key => local calendar interval spec. */
    public const RANGES = [
        '24h' => 'P1D',
        '7d' => 'P7D',
        '30d' => 'P30D',
    ];

    /** Range key => nominal length in seconds (used for ordering/clamping). */
    public const RANGE_SECONDS = [
        '24h' => 86400,
        '7d' => 604800,
        '30d' => 2592000,
    ];

    public const RANGE_LABELS = [
        '24h' => '24h',
        '7d' => '7d',
        '30d' => '30d',
    ];

    /** Hard cap on the number of aggregation buckets rendered. */
    private const MAX_BUCKETS = 31;

    public function __construct(private readonly Database $db)
    {
    }

    public static function normalizeRange(mixed $value, string $default = '7d'): string
    {
        return is_string($value) && isset(self::RANGES[$value]) ? $value : $default;
    }

    /**
     * Dashboard history: rolling window [now - range, now] with individual
     * completed entries as leaves. The running timer is shown separately on the
     * dashboard, so it is excluded here.
     */
    public function history(int $userId, string $range, ?int $now = null): array
    {
        $now ??= time();
        $range = self::normalizeRange($range);
        $startUtc = $now - self::RANGE_SECONDS[$range];

        $rows = $this->fetchEntries($userId, $startUtc, $now, $now, null, false);
        $tree = $this->buildTree($rows, $startUtc, $now, $now, true);

        return [
            'range' => $range,
            'start_utc' => $startUtc,
            'end_utc' => $now,
        ] + $tree;
    }

    /**
     * Reports page: window starting at $startLocal (Y-m-d\TH:i in the user's
     * timezone) spanning $range, plus the same window split into $aggregate
     * sized buckets. Optionally filtered to a single task.
     */
    public function report(
        int $userId,
        string $timezone,
        string $range,
        string $aggregate,
        string $startLocal,
        ?int $taskId = null,
        ?int $now = null
    ): array {
        $now ??= time();
        $tz = new DateTimeZone($timezone);
        $range = self::normalizeRange($range);
        $aggregate = self::normalizeRange($aggregate, '24h');
        if (self::RANGE_SECONDS[$aggregate] > self::RANGE_SECONDS[$range]) {
            $aggregate = $range;
        }

        $startDefaulted = false;
        $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $startLocal, $tz);
        if ($start === false) {
            $start = self::defaultReportStart($range, $tz, $now);
            $startDefaulted = true;
        }
        $end = $start->add(new DateInterval(self::RANGES[$range]));

        $startUtc = $start->getTimestamp();
        $endUtc = $end->getTimestamp();

        $rows = $this->fetchEntries($userId, $startUtc, $endUtc, $now, $taskId, true);
        $overall = $this->buildTree($rows, $startUtc, $endUtc, $now, true);

        $buckets = [];
        $step = new DateInterval(self::RANGES[$aggregate]);
        $bucketStart = $start;
        while ($bucketStart < $end && count($buckets) < self::MAX_BUCKETS) {
            $bucketEnd = $bucketStart->add($step);
            if ($bucketEnd > $end) {
                $bucketEnd = $end;
            }
            $tree = $this->buildTree($rows, $bucketStart->getTimestamp(), $bucketEnd->getTimestamp(), $now, false);
            $buckets[] = [
                'start_local' => $bucketStart,
                'end_local' => $bucketEnd,
            ] + $tree;
            $bucketStart = $bucketEnd;
        }

        return [
            'range' => $range,
            'aggregate' => $aggregate,
            'task_id' => $taskId,
            'start_local' => $start,
            'end_local' => $end,
            'start_input' => $start->format('Y-m-d\TH:i'),
            'start_defaulted' => $startDefaulted,
            'overall' => $overall,
            'buckets' => $buckets,
        ];
    }

    /**
     * Default report start: local midnight such that the window ends at the
     * end of today (24h => today, 7d => last 7 days incl. today, ...).
     */
    public static function defaultReportStart(string $range, DateTimeZone $tz, ?int $now = null): DateTimeImmutable
    {
        $today = (new DateTimeImmutable('@' . ($now ?? time())))->setTimezone($tz)->setTime(0, 0);
        $days = intdiv(self::RANGE_SECONDS[self::normalizeRange($range)], 86400);
        return $today->modify('-' . ($days - 1) . ' days');
    }

    private function fetchEntries(int $userId, int $startUtc, int $endUtc, int $now, ?int $taskId, bool $includeRunning): array
    {
        $sql = 'SELECT e.id, e.started_at_utc, e.ended_at_utc, t.id AS task_id, t.name AS task_name, c.id AS category_id, c.name AS category_name'
            . ' FROM time_entries e'
            . ' JOIN tasks t ON t.id = e.task_id AND t.user_id = e.user_id'
            . ' JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id'
            . ' WHERE e.user_id = :user AND e.started_at_utc < :period_end AND COALESCE(e.ended_at_utc, :now) > :period_start';
        if (!$includeRunning) {
            $sql .= ' AND e.ended_at_utc IS NOT NULL';
        }
        if ($taskId !== null && $taskId > 0) {
            $sql .= ' AND e.task_id = :task';
        }
        $sql .= ' ORDER BY e.started_at_utc DESC, e.id DESC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':period_end', $endUtc, PDO::PARAM_INT);
        $stmt->bindValue(':period_start', $startUtc, PDO::PARAM_INT);
        $stmt->bindValue(':now', $now, PDO::PARAM_INT);
        if ($taskId !== null && $taskId > 0) {
            $stmt->bindValue(':task', $taskId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Groups entry rows into categories > tasks (> items), summing only the
     * part of each entry that falls inside [startUtc, endUtc).
     *
     * @return array{categories: list<array>, total_seconds: int}
     */
    private function buildTree(array $rows, int $startUtc, int $endUtc, int $now, bool $withItems): array
    {
        $categories = [];
        $total = 0;

        foreach ($rows as $row) {
            $entryStart = (int) $row['started_at_utc'];
            $entryEnd = $row['ended_at_utc'] === null ? $now : (int) $row['ended_at_utc'];
            $clipped = min($entryEnd, $endUtc) - max($entryStart, $startUtc);
            if ($clipped <= 0) {
                continue;
            }

            $catId = (int) $row['category_id'];
            $taskId = (int) $row['task_id'];

            $categories[$catId] ??= [
                'id' => $catId,
                'name' => (string) $row['category_name'],
                'seconds' => 0,
                'tasks' => [],
            ];
            $categories[$catId]['tasks'][$taskId] ??= [
                'id' => $taskId,
                'name' => (string) $row['task_name'],
                'seconds' => 0,
                'items' => [],
            ];

            $categories[$catId]['seconds'] += $clipped;
            $categories[$catId]['tasks'][$taskId]['seconds'] += $clipped;
            $total += $clipped;

            if ($withItems) {
                $categories[$catId]['tasks'][$taskId]['items'][] = [
                    'id' => (int) $row['id'],
                    'started_at_utc' => $entryStart,
                    'ended_at_utc' => $row['ended_at_utc'] === null ? null : $entryEnd,
                    'seconds' => $entryEnd - $entryStart,
                    'clipped_seconds' => $clipped,
                ];
            }
        }

        $byName = static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']);
        uasort($categories, $byName);
        foreach ($categories as &$category) {
            uasort($category['tasks'], $byName);
            $category['tasks'] = array_values($category['tasks']);
        }
        unset($category);

        return [
            'categories' => array_values($categories),
            'total_seconds' => $total,
        ];
    }
}

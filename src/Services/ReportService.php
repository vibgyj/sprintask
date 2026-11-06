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
    /**
     * Range key => local calendar interval spec and nominal length in seconds.
     * Keys double as display labels; order is smallest to largest.
     */
    public const RANGES = [
        '24h' => ['interval' => 'P1D', 'seconds' => 86400],
        '7d' => ['interval' => 'P7D', 'seconds' => 604800],
        '30d' => ['interval' => 'P30D', 'seconds' => 2592000],
    ];

    public const DEFAULT_HISTORY_RANGE = '7d';

    /** Hard cap on the number of aggregation buckets rendered. */
    private const MAX_BUCKETS = 31;

    public function __construct(private readonly Database $db)
    {
    }

    public static function normalizeRange(mixed $value, string $default = self::DEFAULT_HISTORY_RANGE): string
    {
        return is_string($value) && isset(self::RANGES[$value]) ? $value : $default;
    }

    public static function rangeSeconds(string $range): int
    {
        return self::RANGES[self::normalizeRange($range)]['seconds'];
    }

    public static function rangeInterval(string $range): DateInterval
    {
        return new DateInterval(self::RANGES[self::normalizeRange($range)]['interval']);
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
        $startUtc = $now - self::rangeSeconds($range);

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
        if (self::rangeSeconds($aggregate) > self::rangeSeconds($range)) {
            $aggregate = $range;
        }

        $start = self::parseLocalStart($startLocal, $tz);
        $startDefaulted = $start === null;
        $start ??= self::defaultReportStart($range, $tz, $now);
        $end = $start->add(self::rangeInterval($range));

        $startUtc = $start->getTimestamp();
        $endUtc = $end->getTimestamp();

        $rows = $this->fetchEntries($userId, $startUtc, $endUtc, $now, $taskId, true);

        $bounds = [];
        $step = self::rangeInterval($aggregate);
        for ($bucketStart = $start; $bucketStart < $end && count($bounds) < self::MAX_BUCKETS; $bucketStart = $bucketEnd) {
            $bucketEnd = min($bucketStart->add($step), $end);
            $bounds[] = [$bucketStart, $bucketEnd];
        }

        $buckets = [];
        foreach ($this->buildBucketTrees($rows, $bounds, $now) as $i => $tree) {
            $buckets[] = [
                'start_local' => $bounds[$i][0],
                'end_local' => $bounds[$i][1],
            ] + $tree;
        }

        return [
            'range' => $range,
            'aggregate' => $aggregate,
            'task_id' => $taskId,
            'start_local' => $start,
            'end_local' => $end,
            'start_input' => $start->format('Y-m-d\TH:i'),
            'start_defaulted' => $startDefaulted,
            'overall' => $this->buildTree($rows, $startUtc, $endUtc, $now, true),
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
        $days = intdiv(self::rangeSeconds($range), 86400);
        return $today->modify('-' . ($days - 1) . ' days');
    }

    /**
     * Parses a datetime-local value strictly. createFromFormat() rolls
     * overflowing parts over (Feb 31 => Mar 3), so the value must round-trip.
     */
    private static function parseLocalStart(string $value, DateTimeZone $tz): ?DateTimeImmutable
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $tz);
        if ($parsed === false || $parsed->format('Y-m-d\TH:i') !== $value) {
            return null;
        }
        return $parsed;
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
        $acc = self::emptyTree();
        foreach ($rows as $row) {
            [$entryStart, $entryEnd] = self::entrySpan($row, $now);
            $clipped = min($entryEnd, $endUtc) - max($entryStart, $startUtc);
            if ($clipped > 0) {
                self::addToTree($acc, $row, $clipped, $entryStart, $entryEnd, $withItems);
            }
        }
        return self::finishTree($acc);
    }

    /**
     * One pass over the rows for all buckets: each entry is added only to the
     * contiguous buckets it overlaps (found by binary search), instead of
     * scanning every row once per bucket.
     *
     * @param list<array{0: DateTimeImmutable, 1: DateTimeImmutable}> $bounds contiguous, ascending
     * @return list<array{categories: list<array>, total_seconds: int}>
     */
    private function buildBucketTrees(array $rows, array $bounds, int $now): array
    {
        $edges = array_map(static fn(array $b): array => [$b[0]->getTimestamp(), $b[1]->getTimestamp()], $bounds);
        $accs = array_fill(0, count($edges), self::emptyTree());
        $count = count($edges);

        foreach ($rows as $row) {
            [$entryStart, $entryEnd] = self::entrySpan($row, $now);

            // First bucket whose end is after the entry start.
            $lo = 0;
            $hi = $count;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($edges[$mid][1] <= $entryStart) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            for ($i = $lo; $i < $count && $edges[$i][0] < $entryEnd; $i++) {
                $clipped = min($entryEnd, $edges[$i][1]) - max($entryStart, $edges[$i][0]);
                if ($clipped > 0) {
                    self::addToTree($accs[$i], $row, $clipped, $entryStart, $entryEnd, false);
                }
            }
        }

        return array_map([self::class, 'finishTree'], $accs);
    }

    /** @return array{0: int, 1: int} entry [start, end), running entries end at $now */
    private static function entrySpan(array $row, int $now): array
    {
        $start = (int) $row['started_at_utc'];
        return [$start, $row['ended_at_utc'] === null ? $now : (int) $row['ended_at_utc']];
    }

    private static function emptyTree(): array
    {
        return ['categories' => [], 'total_seconds' => 0];
    }

    private static function addToTree(array &$acc, array $row, int $clipped, int $entryStart, int $entryEnd, bool $withItems): void
    {
        $catId = (int) $row['category_id'];
        $taskId = (int) $row['task_id'];

        $acc['categories'][$catId] ??= [
            'id' => $catId,
            'name' => (string) $row['category_name'],
            'seconds' => 0,
            'tasks' => [],
        ];
        $acc['categories'][$catId]['tasks'][$taskId] ??= [
            'id' => $taskId,
            'name' => (string) $row['task_name'],
            'seconds' => 0,
            'items' => [],
        ];

        $acc['categories'][$catId]['seconds'] += $clipped;
        $acc['categories'][$catId]['tasks'][$taskId]['seconds'] += $clipped;
        $acc['total_seconds'] += $clipped;

        if ($withItems) {
            $acc['categories'][$catId]['tasks'][$taskId]['items'][] = [
                'id' => (int) $row['id'],
                'started_at_utc' => $entryStart,
                'ended_at_utc' => $row['ended_at_utc'] === null ? null : $entryEnd,
                'seconds' => $entryEnd - $entryStart,
                'clipped_seconds' => $clipped,
            ];
        }
    }

    /** Sorts categories/tasks by name and drops the id keys. */
    private static function finishTree(array $acc): array
    {
        $byName = static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']);
        $categories = $acc['categories'];
        uasort($categories, $byName);
        foreach ($categories as &$category) {
            uasort($category['tasks'], $byName);
            $category['tasks'] = array_values($category['tasks']);
        }
        unset($category);

        return [
            'categories' => array_values($categories),
            'total_seconds' => $acc['total_seconds'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use App\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class ReportService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function build(int $userId, string $timezone, string $mode, string $anchorDate): array
    {
        $tz = new DateTimeZone($timezone);
        $anchor = new DateTimeImmutable($anchorDate . ' 12:00:00', $tz);

        if ($mode === 'month') {
            $startLocal = $anchor->modify('first day of this month')->setTime(0, 0);
            $endLocal = $startLocal->add(new DateInterval('P1M'));
        } else {
            $day = (int) $anchor->format('N');
            $startLocal = $anchor->modify('-' . ($day - 1) . ' days')->setTime(0, 0);
            $endLocal = $startLocal->add(new DateInterval('P7D'));
            $mode = 'week';
        }

        $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->getTimestamp();
        $now = time();

        $stmt = $this->db->pdo()->prepare('SELECT e.*, t.name AS task_name, c.name AS category_name FROM time_entries e JOIN tasks t ON t.id = e.task_id AND t.user_id = e.user_id JOIN categories c ON c.id = t.category_id AND c.user_id = t.user_id WHERE e.user_id = :user AND e.started_at_utc < :period_end AND COALESCE(e.ended_at_utc, :now) > :period_start');
        $stmt->bindValue(':user', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':period_end', $endUtc, PDO::PARAM_INT);
        $stmt->bindValue(':period_start', $startUtc, PDO::PARAM_INT);
        $stmt->bindValue(':now', $now, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $tree = [];
        $total = 0;
        foreach ($rows as $row) {
            $entryStart = max((int) $row['started_at_utc'], $startUtc);
            $entryEnd = min((int) ($row['ended_at_utc'] ?? $now), $endUtc);
            if ($entryEnd <= $entryStart) {
                continue;
            }
            $seconds = $entryEnd - $entryStart;
            $category = $row['category_name'];
            $task = $row['task_name'];

            $tree[$category]['seconds'] = ($tree[$category]['seconds'] ?? 0) + $seconds;
            $tree[$category]['tasks'][$task] = ($tree[$category]['tasks'][$task] ?? 0) + $seconds;
            $total += $seconds;
        }

        ksort($tree);
        return [
            'mode' => $mode,
            'anchor' => $anchor->format('Y-m-d'),
            'start_local' => $startLocal,
            'end_local' => $endLocal,
            'tree' => $tree,
            'total_seconds' => $total,
        ];
    }
}

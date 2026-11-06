<?php

declare(strict_types=1);

use App\Util;

if (!function_exists('sprintaskRenderTimeTree')) {
    /**
     * Renders a category > task (> entry) tree with proportional bars.
     *
     * Categories render open and tasks closed, so on every page load the tree
     * shows category > task and hides individual entries until expanded.
     *
     * Options:
     *  - timezone (string)      user timezone for entry times
     *  - with_items (bool)      render entries under each task
     *  - editable (bool)        render edit/delete forms for entries
     *  - url (callable)         route builder (required when editable)
     *  - csrf (string)          CSRF token (required when editable)
     *  - return_range (string)  dashboard range to come back to after edits
     *  - empty_text (string)    message when there is no tracked time
     *  - resume (bool)          render a Resume button on each task row
     *  - running_task_id (int)  task with the running timer (shows "Running")
     */
    function sprintaskRenderTimeTree(array $tree, array $opts): void
    {
        $timezone = (string) ($opts['timezone'] ?? 'UTC');
        $withItems = (bool) ($opts['with_items'] ?? false);
        $editable = (bool) ($opts['editable'] ?? false);
        $resume = (bool) ($opts['resume'] ?? false);
        $runningTaskId = (int) ($opts['running_task_id'] ?? 0);
        $resumeForms = [];
        $total = max(1, (int) ($tree['total_seconds'] ?? 0));
        $categories = $tree['categories'] ?? [];

        if (!$categories) {
            echo '<p class="muted tree-empty">' . Util::e((string) ($opts['empty_text'] ?? 'No tracked time in this range.')) . '</p>';
            return;
        }

        $bar = static function (int $seconds) use ($total): string {
            $pct = round($seconds / $total * 100, 1);
            return '<span class="tree-bar" aria-hidden="true"><span style="width:' . $pct . '%"></span></span>'
                . '<span class="tree-pct">' . $pct . '%</span>';
        };

        // Resume control for a task row. The <form> lives outside <summary>
        // (forms are not allowed there); the button points at it via form=.
        // Ids are keyed by task, so the same task in several trees on one page
        // shares a single form.
        $resumeCell = static function (array $task) use ($resume, $runningTaskId, &$resumeForms): string {
            if (!$resume) {
                return '';
            }
            $taskId = (int) $task['id'];
            if ($taskId === $runningTaskId) {
                return '<span class="tree-action"><span class="badge-running">Running</span></span>';
            }
            $formId = 'resume-task-' . $taskId;
            $resumeForms[$formId] = $taskId;
            return '<span class="tree-action"><button type="submit" form="' . $formId . '" class="btn-resume" title="Start a new timer for ' . Util::e((string) $task['name']) . '">'
                . '<svg viewBox="0 0 16 16" aria-hidden="true"><path d="M5 3.5v9l7-4.5z" fill="currentColor"/></svg>Resume</button></span>';
        };
        $spacer = $resume ? '<span class="tree-action" aria-hidden="true"></span>' : '';

        echo '<div class="time-tree' . ($resume ? ' has-actions' : '') . '">';
        foreach ($categories as $category) {
            echo '<details class="tree-node tree-category" open>';
            echo '<summary><span class="tree-label">' . Util::e((string) $category['name']) . '</span>'
                . $bar((int) $category['seconds'])
                . '<span class="tree-dur">' . Util::e(Util::formatHours((int) $category['seconds'])) . '</span>' . $spacer . '</summary>';
            echo '<div class="tree-children">';

            foreach ($category['tasks'] as $task) {
                $taskSummary = '<span class="tree-label">' . Util::e((string) $task['name']) . '</span>'
                    . $bar((int) $task['seconds'])
                    . '<span class="tree-dur">' . Util::e(Util::formatHours((int) $task['seconds'])) . '</span>';

                if (!$withItems) {
                    echo '<div class="tree-node tree-task tree-leaf"><div class="tree-row">' . $taskSummary . $resumeCell($task) . '</div></div>';
                    continue;
                }

                $count = count($task['items']);
                echo '<details class="tree-node tree-task">';
                echo '<summary><span class="tree-label">' . Util::e((string) $task['name'])
                    . ' <small class="tree-count">' . $count . ' ' . ($count === 1 ? 'entry' : 'entries') . '</small></span>'
                    . $bar((int) $task['seconds'])
                    . '<span class="tree-dur">' . Util::e(Util::formatHours((int) $task['seconds'])) . '</span>'
                    . $resumeCell($task) . '</summary>';
                echo '<ul class="tree-items">';
                foreach ($task['items'] as $item) {
                    sprintaskRenderTreeItem($item, $timezone, $editable, $opts);
                }
                echo '</ul></details>';
            }

            echo '</div></details>';
        }

        static $renderedResumeForms = [];
        $resumeForms = array_diff_key($resumeForms, $renderedResumeForms);
        $renderedResumeForms += $resumeForms;
        if ($resumeForms) {
            $url = $opts['url'];
            $csrf = Util::e((string) $opts['csrf']);
            $range = Util::e((string) ($opts['return_range'] ?? ''));
            foreach ($resumeForms as $formId => $taskId) {
                echo '<form id="' . $formId . '" method="post" action="' . Util::e($url('/timer/resume')) . '" hidden>'
                    . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                    . '<input type="hidden" name="task_id" value="' . $taskId . '">'
                    . '<input type="hidden" name="range" value="' . $range . '">'
                    . '</form>';
            }
        }
        echo '</div>';
    }

    function sprintaskRenderTreeItem(array $item, string $timezone, bool $editable, array $opts): void
    {
        $id = (int) $item['id'];
        $start = (int) $item['started_at_utc'];
        $end = $item['ended_at_utc'] === null ? null : (int) $item['ended_at_utc'];

        $startText = Util::utcToLocalShort($start, $timezone);
        if ($end === null) {
            $endText = 'running';
        } elseif (Util::utcToLocalDate($start, $timezone) === Util::utcToLocalDate($end, $timezone)) {
            $endText = Util::utcToLocalTime($end, $timezone);
        } else {
            $endText = Util::utcToLocalShort($end, $timezone);
        }
        $fullRange = Util::utcToLocalDateTime($start, $timezone) . ' → ' . ($end === null ? 'running' : Util::utcToLocalDateTime($end, $timezone));

        echo '<li class="tree-item">';
        echo '<div class="tree-row">';
        echo '<span class="tree-label" title="' . Util::e($fullRange) . '">' . Util::e($startText) . ' → ' . Util::e($endText) . '</span>';
        // Show the part inside the range so entries add up to the task total;
        // flag entries that extend past the range edge with their full length.
        $clipped = (int) ($item['clipped_seconds'] ?? $item['seconds']);
        $full = (int) $item['seconds'];
        echo '<span class="tree-dur">' . Util::e(Util::formatDuration($clipped));
        if ($clipped < $full) {
            echo ' <small class="tree-partial" title="Only the part inside this range is counted">of ' . Util::e(Util::formatHours($full)) . '</small>';
        }
        echo '</span>';
        if ($editable && $end !== null) {
            echo '<button type="button" class="entry-edit-toggle" data-entry-edit-toggle aria-expanded="false" aria-controls="entry-detail-' . $id . '" data-entry-id="' . $id . '">Edit</button>';
        }
        echo '</div>';

        if ($editable && $end !== null) {
            $url = $opts['url'];
            $csrf = Util::e((string) $opts['csrf']);
            $range = Util::e((string) ($opts['return_range'] ?? ''));
            echo '<div id="entry-detail-' . $id . '" class="entry-detail-wrap" hidden>';
            echo '<form method="post" action="' . Util::e($url('/entries/update')) . '" class="entry-detail-form">'
                . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="entry_id" value="' . $id . '">'
                . '<input type="hidden" name="range" value="' . $range . '">'
                . '<label class="sr-only" for="entry-start-' . $id . '">Start date and time</label>'
                . '<input id="entry-start-' . $id . '" type="datetime-local" name="started_at" value="' . Util::e(Util::utcToLocalInput($start, $timezone)) . '" required>'
                . '<label class="sr-only" for="entry-end-' . $id . '">End date and time</label>'
                . '<input id="entry-end-' . $id . '" type="datetime-local" name="ended_at" value="' . Util::e(Util::utcToLocalInput($end, $timezone)) . '" required>'
                . '<button type="submit">Save</button>'
                . '</form>';
            echo '<form method="post" action="' . Util::e($url('/entries/delete')) . '" class="entry-delete-form" data-confirm="Delete this time entry?">'
                . '<input type="hidden" name="_csrf" value="' . $csrf . '">'
                . '<input type="hidden" name="entry_id" value="' . $id . '">'
                . '<input type="hidden" name="range" value="' . $range . '">'
                . '<button class="danger" type="submit">Delete</button>'
                . '</form>';
            echo '</div>';
        }
        echo '</li>';
    }
}

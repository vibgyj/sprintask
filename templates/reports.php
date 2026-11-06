<?php

use App\Services\ReportService;
use App\Util;

require_once __DIR__ . '/partials/time_tree.php';

$timezone = (string) $user['timezone'];
$overall = $report['overall'];
$selectedTaskId = (int) ($report['task_id'] ?? 0);
$bucketFormat = $report['aggregate'] === '24h' ? 'D d M, h:i A' : 'D d M';
?>

<div class="page-head">
  <h1 class="page-title">Reports</h1>
  <p class="page-sub">Where your time went, by category and task.</p>
</div>

<section class="card">
  <form method="get" action="<?= Util::e($url('/reports')) ?>" class="report-filters" data-report-filters>
    <fieldset class="filter-group">
      <legend>Range</legend>
      <div class="segmented">
        <?php foreach (ReportService::RANGE_LABELS as $key => $label): ?>
          <label><input type="radio" name="range" value="<?= Util::e($key) ?>" <?= $report['range'] === $key ? 'checked' : '' ?> data-autosubmit data-range-input><span><?= Util::e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <fieldset class="filter-group">
      <legend>Aggregate by</legend>
      <div class="segmented">
        <?php foreach (ReportService::RANGE_LABELS as $key => $label): ?>
          <?php $tooBig = ReportService::RANGE_SECONDS[$key] > ReportService::RANGE_SECONDS[$report['range']]; ?>
          <label class="<?= $tooBig ? 'disabled' : '' ?>"><input type="radio" name="aggregate" value="<?= Util::e($key) ?>" <?= $report['aggregate'] === $key ? 'checked' : '' ?> <?= $tooBig ? 'disabled' : '' ?> data-autosubmit><span><?= Util::e($label) ?></span></label>
        <?php endforeach; ?>
      </div>
    </fieldset>

    <div class="filter-group">
      <label class="filter-label" for="report-start">Starting from</label>
      <span class="row start-row">
        <input id="report-start" type="datetime-local" name="start" value="<?= Util::e((string) $report['start_input']) ?>" <?= $report['start_defaulted'] ? 'data-default-start' : '' ?>>
        <button type="submit">Apply</button>
      </span>
    </div>

    <label class="filter-group">
      <span class="filter-label">Task</span>
      <select name="task_id" data-autosubmit>
        <option value="">All tasks</option>
        <?php foreach (($task_options ?? []) as $task): ?>
          <option value="<?= (int) $task['id'] ?>" <?= $selectedTaskId === (int) $task['id'] ? 'selected' : '' ?>><?= Util::e((string) $task['category_name']) ?> / <?= Util::e((string) $task['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <p class="muted report-window">
    <?= Util::e($report['start_local']->format('D d M Y, h:i A')) ?> → <?= Util::e($report['end_local']->format('D d M Y, h:i A')) ?> (<?= Util::e($timezone) ?>)
  </p>
</section>

<div class="report-grid">
  <section class="card">
    <div class="card-head">
      <h3>Whole range</h3>
      <span class="tree-total"><strong><?= Util::e(Util::formatHours((int) $overall['total_seconds'])) ?></strong></span>
    </div>
    <?php sprintaskRenderTimeTree($overall, [
      'timezone' => $timezone,
      'with_items' => true,
      'empty_text' => 'No tracked time in this range.',
    ]); ?>
  </section>

  <section class="card">
    <div class="card-head">
      <h3>Per <?= Util::e($report['aggregate']) ?></h3>
      <span class="muted"><?= count($report['buckets']) ?> <?= count($report['buckets']) === 1 ? 'period' : 'periods' ?></span>
    </div>
    <?php if ((int) $overall['total_seconds'] === 0): ?>
      <p class="muted tree-empty">No tracked time in this range.</p>
    <?php else: ?>
      <div class="bucket-list">
        <?php foreach ($report['buckets'] as $bucket): ?>
          <?php
            $bucketLabel = $bucket['start_local']->format($bucketFormat);
            if ($report['aggregate'] !== '24h') {
                $bucketLabel .= ' – ' . $bucket['end_local']->modify('-1 second')->format($bucketFormat);
            }
            $bucketSeconds = (int) $bucket['total_seconds'];
            $bucketPct = round($bucketSeconds / max(1, (int) $overall['total_seconds']) * 100, 1);
          ?>
          <?php if ($bucketSeconds === 0): ?>
            <div class="bucket bucket-empty">
              <div class="tree-row"><span class="tree-label"><?= Util::e($bucketLabel) ?></span><span class="tree-dur muted">—</span></div>
            </div>
          <?php else: ?>
            <details class="bucket" open>
              <summary>
                <span class="tree-label"><?= Util::e($bucketLabel) ?></span>
                <span class="tree-bar" aria-hidden="true"><span style="width:<?= $bucketPct ?>%"></span></span>
                <span class="tree-pct"><?= $bucketPct ?>%</span>
                <span class="tree-dur"><?= Util::e(Util::formatHours($bucketSeconds)) ?></span>
              </summary>
              <?php sprintaskRenderTimeTree($bucket, ['timezone' => $timezone, 'with_items' => false]); ?>
            </details>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

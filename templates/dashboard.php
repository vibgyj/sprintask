<?php use App\Util; ?>

<?php
$composerCategories = $composer['categories'] ?? [];
$composerTasks = $composer['tasks'] ?? [];
$serverNowUtc = (int) ($server_now_utc ?? time());
$runningElapsedBaseline = 0;
if ($running) {
  $runningStartedAtUtc = (int) ($running['started_at_utc'] ?? 0);
  $runningElapsedBaseline = max(0, $serverNowUtc - $runningStartedAtUtc);
}
?>

<div class="page-head">
  <h1 class="page-title">Hello, <?= Util::e((string) $user['name']) ?></h1>
  <p class="page-sub"><?= $running ? 'A timer is running.' : 'What are you working on?' ?></p>
</div>

<section class="card timer-card">

  <?php if ($running): ?>
    <form method="post" action="<?= Util::e($url('/timer/stop')) ?>" class="timer-composer timer-running" data-running-since="<?= (int) $running['started_at_utc'] ?>" data-elapsed-baseline="<?= $runningElapsedBaseline ?>">
      <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
      <div class="timer-row timer-row-running">
        <label class="sr-only" for="running-category-input">Category</label>
        <input id="running-category-input" value="<?= Util::e((string) $running['category_name']) ?>" disabled aria-label="Running category <?= Util::e((string) $running['category_name']) ?>">
        <label class="sr-only" for="running-task-input">Task</label>
        <input id="running-task-input" value="<?= Util::e((string) $running['task_name']) ?>" disabled aria-label="Running task <?= Util::e((string) $running['task_name']) ?>">
        <button type="submit" class="btn-stop">Stop</button>
        <span class="timer-elapsed-inline"><span class="muted">Running</span> <strong data-elapsed>00:00:00</strong></span>
      </div>
    </form>
  <?php else: ?>
    <form method="post" action="<?= Util::e($url('/timer/start')) ?>" class="timer-composer" data-timer-composer>
      <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
      <input type="hidden" name="category_id" value="" data-selected-category-id>
      <input type="hidden" name="task_id" value="" data-selected-task-id>

      <div class="timer-row">
        <label class="sr-only" for="timer-category-input">Category</label>
        <div class="combobox" data-combobox="category">
          <input id="timer-category-input" name="category_name" placeholder="Category" autocomplete="off" required role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="timer-category-listbox" aria-activedescendant="" data-combobox-input="category">
          <ul id="timer-category-listbox" role="listbox" class="combobox-list" hidden data-combobox-list="category"></ul>
        </div>

        <label class="sr-only" for="timer-task-input">Task</label>
        <div class="combobox" data-combobox="task">
          <input id="timer-task-input" name="task_name" placeholder="Task" autocomplete="off" required role="combobox" aria-autocomplete="list" aria-haspopup="listbox" aria-expanded="false" aria-controls="timer-task-listbox" aria-activedescendant="" data-combobox-input="task">
          <ul id="timer-task-listbox" role="listbox" class="combobox-list" hidden data-combobox-list="task"></ul>
        </div>

        <button type="submit">Start</button>
      </div>
    </form>
  <?php endif; ?>

  <script type="application/json" data-timer-composer-source><?= json_encode(['categories' => $composerCategories, 'tasks' => $composerTasks], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</section>

<?php
require_once __DIR__ . '/partials/time_tree.php';
$historyRange = (string) ($history['range'] ?? '7d');
$historyTotal = (int) ($history['total_seconds'] ?? 0);
?>
<section class="card history-card">
  <div class="card-head">
    <h3>History</h3>
    <nav class="segmented" aria-label="History range">
      <?php foreach (\App\Services\ReportService::RANGE_LABELS as $rangeKey => $rangeLabel): ?>
        <a href="<?= Util::e($url($rangeKey === '7d' ? '/' : '/?range=' . $rangeKey)) ?>" class="<?= $historyRange === $rangeKey ? 'active' : '' ?>"<?= $historyRange === $rangeKey ? ' aria-current="page"' : '' ?>><?= Util::e($rangeLabel) ?></a>
      <?php endforeach; ?>
    </nav>
  </div>
  <p class="tree-total">Last <?= Util::e($historyRange) ?> · <strong><?= Util::e(Util::formatHours($historyTotal)) ?></strong> tracked</p>
  <?php sprintaskRenderTimeTree($history ?? [], [
    'timezone' => (string) $user['timezone'],
    'with_items' => true,
    'editable' => true,
    'url' => $url,
    'csrf' => $csrf_token,
    'return_range' => $historyRange,
    'resume' => true,
    'running_task_id' => $running ? (int) ($running['task_id'] ?? 0) : 0,
    'empty_text' => 'No completed entries in the last ' . $historyRange . '.',
  ]); ?>
</section>

<section class="card">
  <h3>Add manual entry</h3>
  <form method="post" action="<?= Util::e($url('/entries/create')) ?>" class="manual-entry-row">
    <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
    <label class="sr-only" for="manual-task">Task</label>
    <select id="manual-task" name="task_id" required>
      <option value="">Task…</option>
      <?php foreach ($categories as $category): ?>
        <?php foreach ($category['tasks'] as $task): ?>
          <option value="<?= (int) $task['id'] ?>"><?= Util::e((string) $category['name']) ?> / <?= Util::e((string) $task['name']) ?></option>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </select>
    <label class="sr-only" for="manual-start">Start date and time</label>
    <input id="manual-start" type="datetime-local" name="started_at" required>
    <label class="sr-only" for="manual-end">End date and time</label>
    <input id="manual-end" type="datetime-local" name="ended_at" required>
    <button type="submit">Add entry</button>
  </form>
</section>

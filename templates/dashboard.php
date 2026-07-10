<?php use App\Util; ?>

<?php
$composerCategories = $composer['categories'] ?? [];
$composerTasks = $composer['tasks'] ?? [];
?>

<section class="card">
  <h2>Hello, <?= Util::e((string) $user['name']) ?></h2>

  <?php if ($running): ?>
    <form method="post" action="/timer/stop" class="timer-composer timer-running" data-running-since="<?= (int) $running['started_at_utc'] ?>">
      <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
      <div class="timer-row">
        <label class="sr-only" for="running-category-input">Category</label>
        <input id="running-category-input" value="<?= Util::e((string) $running['category_name']) ?>" disabled aria-label="Running category <?= Util::e((string) $running['category_name']) ?>">
        <label class="sr-only" for="running-task-input">Task</label>
        <input id="running-task-input" value="<?= Util::e((string) $running['task_name']) ?>" disabled aria-label="Running task <?= Util::e((string) $running['task_name']) ?>">
        <button type="submit">Stop</button>
      </div>
      <div class="timer-running-meta">
        <span class="muted">Running</span>
        <strong data-elapsed>00:00:00</strong>
      </div>
    </form>
  <?php else: ?>
    <form method="post" action="/timer/start" class="timer-composer" data-timer-composer>
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

<section class="card">
  <h3>Recent entries</h3>
  <div class="table-wrap">
    <table class="entries-table">
      <thead><tr><th>Task</th><th>Start</th><th>End</th><th>Duration</th><th>Edit</th></tr></thead>
      <tbody>
        <?php foreach ($entries as $entry): ?>
          <?php $duration = (int) $entry['ended_at_utc'] - (int) $entry['started_at_utc']; ?>
          <?php $entryLabel = (string) $entry['category_name'] . ' / ' . (string) $entry['task_name']; ?>
          <?php $entryId = (int) $entry['id']; ?>
          <?php $startFull = Util::utcToLocalDateTime((int) $entry['started_at_utc'], (string) $user['timezone']); ?>
          <?php $endFull = Util::utcToLocalDateTime((int) $entry['ended_at_utc'], (string) $user['timezone']); ?>
          <tr class="entry-summary-row">
            <td class="entry-label-cell" title="<?= Util::e($entryLabel) ?>" aria-label="<?= Util::e($entryLabel) ?>"><span class="entry-label-text"><?= Util::e((string) $entry['task_name']) ?></span></td>
            <td title="<?= Util::e($startFull) ?>" aria-label="Start <?= Util::e($startFull) ?>"><?= Util::e(Util::utcToLocalTime((int) $entry['started_at_utc'], (string) $user['timezone'])) ?><span class="sr-only"> <?= Util::e($startFull) ?></span></td>
            <td title="<?= Util::e($endFull) ?>" aria-label="End <?= Util::e($endFull) ?>"><?= Util::e(Util::utcToLocalTime((int) $entry['ended_at_utc'], (string) $user['timezone'])) ?><span class="sr-only"> <?= Util::e($endFull) ?></span></td>
            <td><?= Util::e(Util::formatDuration($duration)) ?></td>
            <td>
              <button type="button" class="entry-edit-toggle" data-entry-edit-toggle aria-expanded="false" aria-controls="entry-detail-<?= $entryId ?>" data-entry-id="<?= $entryId ?>">Edit</button>
            </td>
          </tr>
          <tr id="entry-detail-<?= $entryId ?>" class="entry-detail-row" hidden>
            <td colspan="5">
              <div class="entry-detail-wrap">
                <form method="post" action="/entries/update" class="entry-detail-form">
                  <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
                  <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                  <label class="sr-only" for="entry-start-<?= $entryId ?>">Start date and time</label>
                  <input id="entry-start-<?= $entryId ?>" type="datetime-local" name="started_at" value="<?= Util::e(Util::utcToLocalInput((int) $entry['started_at_utc'], (string) $user['timezone'])) ?>" required>
                  <label class="sr-only" for="entry-end-<?= $entryId ?>">End date and time</label>
                  <input id="entry-end-<?= $entryId ?>" type="datetime-local" name="ended_at" value="<?= Util::e(Util::utcToLocalInput((int) $entry['ended_at_utc'], (string) $user['timezone'])) ?>" required>
                  <button type="submit">Save</button>
                </form>
                <form method="post" action="/entries/delete" class="entry-delete-form">
                  <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
                  <input type="hidden" name="entry_id" value="<?= $entryId ?>">
                  <button class="danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="card">
  <h3>Add manual entry</h3>
  <form method="post" action="/entries/create" class="manual-entry-row">
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

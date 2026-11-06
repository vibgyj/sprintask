<?php

use App\Util;
use App\ViewState;

$grid = $grid ?? ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 10, 'sort' => 'category', 'dir' => 'asc'];
$gridCurrent = ['sort' => $grid['sort'], 'dir' => $grid['dir'], 'per' => (string) $grid['per_page'], 'page' => (string) $grid['page']];

/** Settings URL with the current grid state, overridden by $changes. */
$gridUrl = static fn(array $changes): string => $url(ViewState::settingsPath(array_merge($gridCurrent, $changes)));

$sortLink = static function (string $column, string $label) use ($grid, $gridUrl): string {
    $active = $grid['sort'] === $column;
    $nextDir = $active && $grid['dir'] === 'asc' ? 'desc' : 'asc';
    $arrow = $active ? ($grid['dir'] === 'asc' ? '▲' : '▼') : '↕';
    return '<a class="sort-link' . ($active ? ' active' : '') . '" href="' . Util::e($gridUrl(['sort' => $column, 'dir' => $nextDir, 'page' => '1'])) . '">'
        . Util::e($label) . ' <span aria-hidden="true">' . $arrow . '</span></a>';
};

/** Hidden grid_* fields so POST handlers can redirect back to the same grid view. */
$gridState = static function () use ($gridCurrent): string {
    $html = '';
    foreach ($gridCurrent as $key => $value) {
        $html .= '<input type="hidden" name="grid_' . $key . '" value="' . Util::e($value) . '">';
    }
    return $html;
};

$ariaSort = static fn(string $column): string => $grid['sort'] === $column ? ($grid['dir'] === 'asc' ? 'ascending' : 'descending') : 'none';
$firstRow = $grid['total'] === 0 ? 0 : ($grid['page'] - 1) * $grid['per_page'] + 1;
$lastRow = min($grid['total'], $grid['page'] * $grid['per_page']);
?>

<div class="page-head">
  <h1 class="page-title">Settings</h1>
  <p class="page-sub">Preferences, categories and tasks.</p>
</div>

<section class="card">
  <h3>Preferences</h3>
  <form method="post" action="<?= Util::e($url('/settings')) ?>" class="prefs-form">
    <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
    <label class="field"><span class="field-label">Theme</span>
      <select name="theme" data-theme-select>
        <option value="system" <?= $user['theme'] === 'system' ? 'selected' : '' ?>>System</option>
        <option value="light" <?= $user['theme'] === 'light' ? 'selected' : '' ?>>Light</option>
        <option value="dark" <?= $user['theme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
      </select>
    </label>
    <label class="field"><span class="field-label">Timezone</span>
      <select name="timezone">
        <?php foreach (($timezone_options ?? []) as $option): ?>
          <option value="<?= Util::e((string) $option['value']) ?>" <?= $user['timezone'] === $option['value'] ? 'selected' : '' ?>><?= Util::e((string) $option['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">Save</button>
  </form>
</section>

<section class="card">
  <h3>Categories &amp; tasks</h3>

  <div class="settings-add-row">
    <form method="post" action="<?= Util::e($url('/categories/create')) ?>" class="row">
      <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
      <?= $gridState() ?>
      <label class="sr-only" for="new-category-name">New category</label>
      <input id="new-category-name" name="name" placeholder="New category" required>
      <button type="submit">Add category</button>
    </form>

    <?php if (!empty($categories)): ?>
      <form method="post" action="<?= Util::e($url('/tasks/create')) ?>" class="row">
        <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
        <?= $gridState() ?>
        <label class="sr-only" for="new-task-category">Category for new task</label>
        <select id="new-task-category" name="category_id" required>
          <option value="">Category…</option>
          <?php foreach ($categories as $category): ?>
            <option value="<?= (int) $category['id'] ?>"><?= Util::e((string) $category['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <label class="sr-only" for="new-task-name">New task</label>
        <input id="new-task-name" name="name" placeholder="New task" required>
        <button type="submit">Add task</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="table-wrap">
    <table class="settings-grid">
      <thead>
        <tr>
          <th aria-sort="<?= $ariaSort('category') ?>"><?= $sortLink('category', 'Category') ?></th>
          <th aria-sort="<?= $ariaSort('task') ?>"><?= $sortLink('task', 'Task') ?></th>
          <th class="actions-col">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$grid['rows']): ?>
          <tr><td colspan="3" class="muted">No categories yet. Add one above, or start a timer on the dashboard.</td></tr>
        <?php endif; ?>
        <?php foreach ($grid['rows'] as $i => $row): ?>
          <?php
            $categoryId = (int) $row['category_id'];
            $taskId = $row['task_id'] === null ? null : (int) $row['task_id'];
            $entryCount = (int) $row['entry_count'];
            $formId = 'grid-edit-' . $categoryId . '-' . ($taskId ?? 'c');
            $blocked = $entryCount > 0;
            $blockedReason = $blocked ? sprintf('Has %d time %s', $entryCount, $entryCount === 1 ? 'entry' : 'entries') : '';
          ?>
          <tr class="grid-row" data-grid-row data-category-id="<?= $categoryId ?>">
            <td>
              <span class="grid-view" data-category-label="<?= $categoryId ?>"><?= Util::e((string) $row['category_name']) ?></span>
              <label class="sr-only" for="<?= $formId ?>-category">Category name</label>
              <input class="grid-edit" id="<?= $formId ?>-category" form="<?= $formId ?>" name="category_name" value="<?= Util::e((string) $row['category_name']) ?>" required hidden data-category-input="<?= $categoryId ?>">
            </td>
            <td>
              <?php if ($taskId === null): ?>
                <span class="muted">No tasks</span>
              <?php else: ?>
                <span class="grid-view"><?= Util::e((string) $row['task_name']) ?></span>
                <label class="sr-only" for="<?= $formId ?>-task">Task name</label>
                <input class="grid-edit" id="<?= $formId ?>-task" form="<?= $formId ?>" name="task_name" value="<?= Util::e((string) $row['task_name']) ?>" required hidden>
              <?php endif; ?>
            </td>
            <td class="actions-col">
              <form id="<?= $formId ?>" method="post" action="<?= Util::e($url('/settings/rows/update')) ?>" class="grid-actions">
                <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
                <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                <input type="hidden" name="task_id" value="<?= $taskId ?? '' ?>">
                <?= $gridState() ?>
                <button type="button" class="grid-view btn-small btn-ghost" data-grid-edit>Edit</button>
                <button type="submit" class="grid-edit btn-small" hidden>Save</button>
                <button type="button" class="grid-edit btn-small btn-ghost" data-grid-cancel hidden>Cancel</button>
              </form>
              <form method="post" action="<?= Util::e($url($taskId === null ? '/categories/delete' : '/tasks/delete')) ?>" class="grid-actions grid-view" data-confirm="<?= Util::e($taskId === null ? 'Delete category “' . $row['category_name'] . '”?' : 'Delete task “' . $row['task_name'] . '”?') ?>">
                <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
                <?php if ($taskId === null): ?>
                  <input type="hidden" name="category_id" value="<?= $categoryId ?>">
                <?php else: ?>
                  <input type="hidden" name="task_id" value="<?= $taskId ?>">
                <?php endif; ?>
                <?= $gridState() ?>
                <button type="submit" class="danger btn-small" <?= $blocked ? 'disabled aria-disabled="true"' : '' ?> title="<?= Util::e($blocked ? $blockedReason . ' — delete those first' : 'Delete') ?>">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="grid-footer">
    <form method="get" action="<?= Util::e($url('/settings')) ?>" class="row">
      <input type="hidden" name="sort" value="<?= Util::e($grid['sort']) ?>">
      <input type="hidden" name="dir" value="<?= Util::e($grid['dir']) ?>">
      <label for="grid-per" class="muted">Rows per page</label>
      <select id="grid-per" name="per" data-autosubmit>
        <?php foreach ([10, 50, 100] as $size): ?>
          <option value="<?= $size ?>" <?= (int) $grid['per_page'] === $size ? 'selected' : '' ?>><?= $size ?></option>
        <?php endforeach; ?>
      </select>
      <noscript><button type="submit">Apply</button></noscript>
    </form>

    <span class="muted"><?= $firstRow ?>–<?= $lastRow ?> of <?= (int) $grid['total'] ?></span>

    <nav class="pager" aria-label="Pagination">
      <?php if ($grid['page'] > 1): ?>
        <a href="<?= Util::e($gridUrl(['page' => '1'])) ?>" aria-label="First page">«</a>
        <a href="<?= Util::e($gridUrl(['page' => (string) ($grid['page'] - 1)])) ?>" aria-label="Previous page">‹</a>
      <?php else: ?>
        <span class="disabled" aria-hidden="true">«</span><span class="disabled" aria-hidden="true">‹</span>
      <?php endif; ?>
      <span class="pager-current">Page <?= (int) $grid['page'] ?> of <?= (int) $grid['pages'] ?></span>
      <?php if ($grid['page'] < $grid['pages']): ?>
        <a href="<?= Util::e($gridUrl(['page' => (string) ($grid['page'] + 1)])) ?>" aria-label="Next page">›</a>
        <a href="<?= Util::e($gridUrl(['page' => (string) $grid['pages']])) ?>" aria-label="Last page">»</a>
      <?php else: ?>
        <span class="disabled" aria-hidden="true">›</span><span class="disabled" aria-hidden="true">»</span>
      <?php endif; ?>
    </nav>
  </div>
</section>

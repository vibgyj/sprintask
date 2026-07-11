<?php use App\Util; ?>

<section class="card">
  <h2>Settings</h2>
</section>

<section class="card">
  <h3>Preferences</h3>
  <form method="post" action="<?= Util::e($url('/settings')) ?>" class="stacked-form">
    <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
    <label>Theme
      <select name="theme" data-theme-select>
        <option value="system" <?= $user['theme'] === 'system' ? 'selected' : '' ?>>System</option>
        <option value="light" <?= $user['theme'] === 'light' ? 'selected' : '' ?>>Light</option>
        <option value="dark" <?= $user['theme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
      </select>
    </label>
    <label>Timezone
      <select name="timezone">
        <?php foreach (($timezone_options ?? []) as $option): ?>
          <option value="<?= Util::e((string) $option['value']) ?>" <?= $user['timezone'] === $option['value'] ? 'selected' : '' ?>><?= Util::e((string) $option['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit">Save settings</button>
  </form>
</section>

<section class="card">
  <h3>Manage categories & tasks</h3>

  <form method="post" action="<?= Util::e($url('/categories/create')) ?>" class="row">
    <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
    <input name="name" placeholder="New category" required>
    <button type="submit">Add category</button>
  </form>

  <?php foreach (($categories ?? []) as $category): ?>
    <div class="card settings-manage-card">
      <strong><?= Util::e((string) $category['name']) ?></strong>
      <form method="post" action="<?= Util::e($url('/categories/update')) ?>" class="row" style="margin-top:0.4rem;">
        <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
        <input name="name" value="<?= Util::e((string) $category['name']) ?>" required>
        <button type="submit">Rename</button>
      </form>
      <form method="post" action="<?= Util::e($url('/categories/delete')) ?>" class="row" onsubmit="return confirm('Delete category and all tasks/entries? Type DELETE in field.')">
        <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
        <input name="confirm" placeholder="Type DELETE" required>
        <button class="danger" type="submit">Delete category</button>
      </form>

      <form method="post" action="<?= Util::e($url('/tasks/create')) ?>" class="row" style="margin-top:0.4rem;">
        <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
        <input type="hidden" name="category_id" value="<?= (int) $category['id'] ?>">
        <input name="name" placeholder="New task" required>
        <button type="submit">Add task</button>
      </form>

      <ul>
        <?php foreach ($category['tasks'] as $task): ?>
          <li>
            <?= Util::e((string) $task['name']) ?>
            <form method="post" action="<?= Util::e($url('/tasks/update')) ?>" style="display:inline-block;">
              <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
              <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
              <input name="name" value="<?= Util::e((string) $task['name']) ?>" required>
              <button type="submit">Rename</button>
            </form>
            <form method="post" action="<?= Util::e($url('/tasks/delete')) ?>" style="display:inline-block;" onsubmit="return confirm('Delete task and all entries?')">
              <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
              <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
              <input name="confirm" placeholder="DELETE" required>
              <button class="danger" type="submit">Delete</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endforeach; ?>
</section>

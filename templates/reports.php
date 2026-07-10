<?php use App\Util; ?>

<section class="card">
  <h2>Reports</h2>
  <form method="get" action="/reports" class="row">
    <select name="mode">
      <option value="week" <?= $report['mode'] === 'week' ? 'selected' : '' ?>>Week</option>
      <option value="month" <?= $report['mode'] === 'month' ? 'selected' : '' ?>>Month</option>
    </select>
    <input type="date" name="date" value="<?= Util::e((string) $report['anchor']) ?>">
    <button type="submit">Apply</button>
  </form>
  <p class="muted">
    <?= Util::e($report['start_local']->format('Y-m-d H:i')) ?> → <?= Util::e($report['end_local']->format('Y-m-d H:i')) ?> (<?= Util::e((string) $user['timezone']) ?>)
  </p>
</section>

<section class="card">
  <h3>Total: <?= Util::e(Util::formatDuration((int) $report['total_seconds'])) ?></h3>
  <?php
    $max = 1;
    foreach ($report['tree'] as $category) {
      $max = max($max, (int) $category['seconds']);
    }
    $y = 24;
  ?>
  <svg viewBox="0 0 900 <?= max(120, count($report['tree']) * 32 + 24) ?>" role="img" aria-label="Category totals">
    <?php foreach ($report['tree'] as $name => $category): ?>
      <?php $width = (int) round(((int) $category['seconds'] / $max) * 620); ?>
      <text x="10" y="<?= $y ?>" fill="currentColor" font-size="12"><?= Util::e((string) $name) ?></text>
      <rect x="220" y="<?= $y - 12 ?>" width="<?= $width ?>" height="16" rx="6" fill="url(#g)"/>
      <text x="<?= 230 + $width ?>" y="<?= $y ?>" fill="currentColor" font-size="12"><?= Util::e(Util::formatDuration((int) $category['seconds'])) ?></text>
      <?php $y += 30; ?>
    <?php endforeach; ?>
    <defs>
      <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
        <stop offset="0%" stop-color="#4f46e5"/>
        <stop offset="100%" stop-color="#06b6d4"/>
      </linearGradient>
    </defs>
  </svg>
</section>

<section class="card">
  <h3>Hierarchy</h3>
  <table>
    <thead><tr><th>Category / Task</th><th>Duration</th></tr></thead>
    <tbody>
      <?php foreach ($report['tree'] as $name => $category): ?>
        <tr>
          <td><strong><?= Util::e((string) $name) ?></strong></td>
          <td><strong><?= Util::e(Util::formatDuration((int) $category['seconds'])) ?></strong></td>
        </tr>
        <?php foreach ($category['tasks'] as $taskName => $seconds): ?>
          <tr>
            <td>&nbsp;&nbsp;↳ <?= Util::e((string) $taskName) ?></td>
            <td><?= Util::e(Util::formatDuration((int) $seconds)) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>

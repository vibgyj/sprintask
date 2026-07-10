<?php

use App\Util;

$pageTemplate = __DIR__ . '/' . $template;
$themeAttr = '';
if (!empty($current_user) && in_array(($current_user['theme'] ?? 'system'), ['light', 'dark'], true)) {
    $themeAttr = ' data-theme="' . Util::e((string) $current_user['theme']) . '"';
}
$isAuthPage = in_array($template, ['auth_request.php', 'auth_verify.php'], true);
$isSettingsPage = $template === 'settings.php';
$containerClass = $isAuthPage ? 'container auth-layout' : 'container';
$bodyClass = $isSettingsPage ? 'settings-page' : ($template === 'dashboard.php' ? 'dashboard-page' : '');
?>
<!doctype html>
<html lang="en"<?= $themeAttr ?>>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Sprintask</title>
  <link rel="stylesheet" href="/assets/styles.css">
</head>
<body class="<?= Util::e($bodyClass) ?>">
  <main class="<?= Util::e($containerClass) ?>">
    <?php if ($isAuthPage): ?>
      <section class="auth-shell" aria-label="Authentication">
        <h1 class="auth-title">Sprintask</h1>
        <?php require $pageTemplate; ?>
        <?php foreach (($flash ?? []) as $item): ?>
          <div class="flash <?= Util::e((string) $item['type']) ?>"><?= Util::e((string) $item['message']) ?></div>
        <?php endforeach; ?>
      </section>
    <?php else: ?>
      <div class="row" style="justify-content:space-between; margin-bottom: 1rem;">
        <h1>Sprintask</h1>
        <div class="row">
          <?php if (!empty($current_user)): ?>
            <a href="/">Dashboard</a>
            <a href="/reports">Reports</a>
            <a href="/settings">Settings</a>
            <form method="post" action="/logout" style="margin:0;">
              <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
              <button type="submit">Log out</button>
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php foreach (($flash ?? []) as $item): ?>
        <div class="flash <?= Util::e((string) $item['type']) ?>"><?= Util::e((string) $item['message']) ?></div>
      <?php endforeach; ?>

      <?php require $pageTemplate; ?>
    <?php endif; ?>
  </main>
  <script src="/assets/app.js"></script>
</body>
</html>

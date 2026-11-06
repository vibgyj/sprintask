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
  <link rel="stylesheet" href="<?= Util::e($url('/assets/styles.css')) ?>">
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
      <header class="topbar">
        <a class="brand" href="<?= Util::e($url('/')) ?>"><span class="brand-mark" aria-hidden="true"></span>Sprintask</a>
        <?php if (!empty($current_user)): ?>
          <nav class="nav" aria-label="Main">
            <?php foreach (['dashboard.php' => ['/', 'Dashboard'], 'reports.php' => ['/reports', 'Reports'], 'settings.php' => ['/settings', 'Settings']] as $navTemplate => [$navPath, $navLabel]): ?>
              <a href="<?= Util::e($url($navPath)) ?>"<?= $template === $navTemplate ? ' aria-current="page"' : '' ?>><?= Util::e($navLabel) ?></a>
            <?php endforeach; ?>
            <form method="post" action="<?= Util::e($url('/logout')) ?>" class="logout-form">
              <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
              <button type="submit" class="btn-ghost btn-small">Log out</button>
            </form>
          </nav>
        <?php endif; ?>
      </header>

      <?php foreach (($flash ?? []) as $item): ?>
        <div class="flash <?= Util::e((string) $item['type']) ?>"><?= Util::e((string) $item['message']) ?></div>
      <?php endforeach; ?>

      <?php require $pageTemplate; ?>
    <?php endif; ?>
  </main>
  <script src="<?= Util::e($url('/assets/app.js')) ?>"></script>
</body>
</html>

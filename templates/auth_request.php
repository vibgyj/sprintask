<?php use App\Util; ?>
<section class="card auth-card">
  <h2><?= $purpose === 'register' ? 'Create account' : 'Sign in' ?></h2>
  <form method="post" action="<?= $purpose === 'register' ? '/auth/register' : '/auth/login' ?>" class="stacked-form">
    <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
    <?php if ($purpose === 'register'): ?>
      <input name="name" placeholder="Name" required>
    <?php endif; ?>
    <input type="email" name="email" placeholder="Email" required>
    <button type="submit">Send OTP</button>
  </form>
  <p class="muted">
    <?php if ($purpose === 'register'): ?>
      Already have an account? <a href="/auth/login">Log in</a>
    <?php else: ?>
      New here? <a href="/auth/register">Register</a>
    <?php endif; ?>
  </p>
</section>

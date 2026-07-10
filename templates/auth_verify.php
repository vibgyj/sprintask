<?php use App\Util; ?>
<section class="card auth-card">
  <h2>Verify OTP</h2>
  <p class="muted">Check your email for a 6-digit code. It expires in 10 minutes.</p>
  <form method="post" action="<?= $purpose === 'register' ? '/auth/register/verify' : '/auth/login/verify' ?>" class="stacked-form">
    <input type="hidden" name="_csrf" value="<?= Util::e($csrf_token) ?>">
    <input type="text" value="<?= Util::e((string) $email) ?>" readonly>
    <input name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="123456" required>
    <button type="submit">Verify</button>
  </form>
</section>

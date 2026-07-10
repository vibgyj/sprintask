<?php use App\Util; ?>
<section class="card">
  <h2>Error</h2>
  <p><?= Util::e((string) ($message ?? 'Unknown error')) ?></p>
</section>

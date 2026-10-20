<div class="card plain-card stack">
  <p class="muted small">Chyba <?= (int) $code ?></p>
  <h1 style="font-size: 30px"><?= e($title) ?></h1>
  <?php if ($message !== ''): ?><p><?= e($message) ?></p><?php endif; ?>
  <p><a href="javascript:history.back()">Zpět</a></p>
</div>

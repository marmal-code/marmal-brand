<?php $title = 'Připojit AI aplikaci'; ?>
<?php if (!empty($error)): ?>
<div class="card plain-card stack-lg">
  <div class="stack" style="gap: 6px">
    <span style="font-family: var(--font-display); font-weight: 700; font-size: 24px">MarMal</span>
    <h1 style="font-size: 30px">Připojení AI aplikace</h1>
  </div>
  <div class="alert alert-error" role="alert"><?= icon('alert') ?><div><?= e($error) ?></div></div>
  <a class="btn btn-ghost btn-block" href="/admin">Do administrace</a>
</div>
<?php else: ?>
<form method="post" action="/oauth/authorize" class="card plain-card stack-lg">
  <?= csrf_field() ?>
  <input type="hidden" name="rid" value="<?= e($rid) ?>">
  <div class="stack" style="gap: 6px">
    <span style="font-family: var(--font-display); font-weight: 700; font-size: 24px">MarMal</span>
    <h1 style="font-size: 30px">Připojit AI aplikaci</h1>
    <p class="muted"><b style="color: var(--ink)"><?= e($client['name']) ?></b> chce přístup k Brand Hubu<?php if ($returnHost !== ''): ?> (po povolení se vrátíte na <b style="color: var(--ink)"><?= e($returnHost) ?></b>)<?php endif; ?>.</p>
  </div>
  <div class="stack" style="gap: 4px">
    <label class="check"><input type="checkbox" checked disabled><span><b>Čtení</b> – <?= e(substr(MCP_SCOPES['cteni'], strlen('Čtení – '))) ?></span></label>
    <label class="check"><input type="checkbox" name="write" value="1"<?= $writeDefault ? ' checked' : '' ?>><span><b>Zápis</b> – <?= e(substr(MCP_SCOPES['zapis'], strlen('Zápis – '))) ?></span></label>
  </div>
  <p class="small muted">Hesla klientů a sdílené odkazy konektor nikdy neposílá. Které nástroje může AI použít, nastavíte a připojení kdykoli odpojíte v Nastavení → AI konektor.</p>
  <div class="stack" style="gap: 12px">
    <button class="btn btn-primary btn-block" name="action" value="allow"><?= icon('check', 18) ?>Povolit</button>
    <button class="btn btn-ghost btn-block" name="action" value="deny">Zamítnout</button>
  </div>
</form>
<?php endif; ?>

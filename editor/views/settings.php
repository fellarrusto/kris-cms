<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<div class="container narrow"><header class="page-heading"><div><p class="eyebrow">CONFIGURAZIONE</p><h1>Impostazioni</h1><p>Le preferenze che aiutano a lavorare meglio.</p></div></header>
<form method="POST" id="settingsForm" data-dirty-form data-async-save>
<input type="hidden" name="save_settings" value="1">
<section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('settings') ?></span><div><h2>Lingue di modifica</h2><p>Scegli le lingue disponibili nei moduli dell’editor.</p></div></header><div class="card-body">
<?php foreach ($DEFAULT_LANGS as $code => $label): ?><label class="setting-row"><input type="checkbox" name="langs[]" value="<?= h($code) ?>" <?= in_array($code, $activeLangs) ? 'checked' : '' ?>><span><strong><?= h($label) ?></strong><small><?= strtoupper($code) ?></small></span></label><?php endforeach; ?>
<div class="notice"><strong>I testi restano al sicuro.</strong><p>Disattivare una lingua la nasconde nei moduli, ma conserva le traduzioni già salvate. La navigazione del sito dipende dai suoi template.</p></div></div></section>
<?php require __DIR__ . '/../partials/save_bar.php'; ?>
</form></div>

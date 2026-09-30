<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<div class="container narrow"><header class="page-heading"><div><p class="eyebrow">CONFIGURAZIONE</p><h1>Impostazioni</h1><p>Le preferenze che aiutano a lavorare meglio.</p></div></header>
<form method="POST" id="settingsForm" data-dirty-form data-async-save>
<input type="hidden" name="save_settings" value="1">
<section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('settings') ?></span><div><h2>Lingue di modifica</h2><p>Scegli le lingue disponibili nei moduli dell’editor.</p></div></header><div class="card-body">
<?php foreach ($DEFAULT_LANGS as $code => $label): ?><label class="setting-row"><input type="checkbox" name="langs[]" value="<?= h($code) ?>" <?= in_array($code, $activeLangs) ? 'checked' : '' ?>><span><strong><?= h($label) ?></strong><small><?= strtoupper($code) ?></small></span></label><?php endforeach; ?>
<div class="notice"><strong>I testi restano al sicuro.</strong><p>Disattivare una lingua la nasconde nei moduli, ma conserva le traduzioni già salvate. La navigazione del sito dipende dai suoi template.</p></div></div></section>
<?php require __DIR__ . '/../partials/save_bar.php'; ?>
</form>
<?php
// Lo stato degli aggiornamenti e informativo: se il file e rovinato lo si
// segnala qui, senza impedire di lavorare sui contenuti.
$stateError = false;
try {
    $siteState = \Kris\Update\SiteState::load();
} catch (\Kris\Entity\StorageException) {
    $siteState = null;
    $stateError = true;
}
$lastCheck = is_array($siteState['last_check'] ?? null) ? $siteState['last_check'] : null;
$lastUpdate = $siteState ? \Kris\Update\SiteState::lastUpdate($siteState) : null;
$outcomes = ['ok' => 'riuscito', 'rollback' => 'annullato, sito ripristinato', 'error' => 'non riuscito'];
?>
<section class="card" id="versione"><header class="card-header"><span class="collection-icon"><?= uiIcon('check') ?></span><div><h2>Versione di Kris</h2><p>Il programma che fa funzionare il sito e questo editor.</p></div></header><div class="card-body">
<?php if ($stateError): ?><div class="alert alert-error" role="alert">Lo storico degli aggiornamenti non è leggibile (file <code>data/kris_state.json</code>). I contenuti non sono coinvolti: segnalalo a chi gestisce il sito.</div><?php endif; ?>
<div class="setting-row static"><span><strong>Versione installata</strong><small>Indicala quando chiedi assistenza.</small></span><span class="setting-value">Kris <?= h($krisVersion) ?></span></div>
<div class="setting-row static"><span><strong>Formato dei contenuti</strong><small>Cambia solo quando un aggiornamento converte i dati.</small></span><span class="setting-value"><?= isset($siteState['data_version']) ? h($siteState['data_version']) : 'Non ancora registrato' ?></span></div>
<div class="setting-row static"><span><strong>Ultimo controllo degli aggiornamenti</strong></span><span class="setting-value"><?= h(editorDateTime($lastCheck['at'] ?? null) ?? 'Mai') ?></span></div>
<div class="setting-row static"><span><strong>Ultimo aggiornamento</strong><?php if ($lastUpdate): ?><small>Da <?= h($lastUpdate['from'] ?? '?') ?> a <?= h($lastUpdate['to'] ?? '?') ?>, <?= h($outcomes[$lastUpdate['outcome'] ?? ''] ?? 'esito sconosciuto') ?></small><?php endif; ?></span><span class="setting-value"><?= $lastUpdate ? h(editorDateTime($lastUpdate['at'] ?? null) ?? 'Data sconosciuta') : 'Nessuno' ?></span></div>
</div></section>
</div>

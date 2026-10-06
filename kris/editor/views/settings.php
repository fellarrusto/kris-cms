<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<div class="container narrow"><header class="page-heading"><div><h1>Impostazioni</h1></div></header>
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
$history = array_reverse(array_slice(is_array($siteState['history'] ?? null) ? $siteState['history'] : [], -5));
$outcomes = [
    'ok' => 'riuscito',
    'rollback' => 'annullato, sito ripristinato',
    'error' => 'non riuscito',
    'manual_rollback' => 'tornati alla versione precedente',
    'ftp' => 'file caricati a mano',
];
// Il registro e un file: una voce malformata non deve rompere la pagina.
$field = fn(array $entry, string $key): string => is_scalar($entry[$key] ?? null) ? (string) $entry[$key] : '';
$history = array_values(array_filter($history, 'is_array'));
$lastUpdate = is_array($lastUpdate) ? $lastUpdate : null;
$updater = new \Kris\Update\Updater();
$rollback = $updater->enabled() && $maintenance === null ? $updater->availableRollback() : null;
?>
<?php $account = kris_auth_config() ?? []; $accountEmail = (string) ($account['email'] ?? ''); ?>
<section class="card" id="account"><header class="card-header"><span class="collection-icon"><?= uiIcon('settings') ?></span><div><h2>Account</h2><p>Accedi come <strong><?= h($account['user'] ?? '') ?></strong>.</p></div></header><div class="card-body account-body">
<form method="POST" class="account-form"><input type="hidden" name="save_account_email" value="1">
    <label for="account-email">Email per recuperare la password</label>
    <div class="account-row"><input id="account-email" type="email" name="account_email" value="<?= h($accountEmail) ?>" autocomplete="email" placeholder="nome@esempio.it" required><button class="btn btn-white">Salva email</button></div>
</form>
<?php if (Kris\Auth\Credentials::validEmail($accountEmail)): ?>
<form method="POST" class="account-form"><input type="hidden" name="send_test_email" value="1"><p class="hint">Inviati un'email di prova: se non arriva, su questo hosting il recupero della password via email non funziona.</p><button class="btn btn-white">Invia email di prova</button></form>
<?php endif; ?>
<form method="POST" class="account-form"><input type="hidden" name="change_password" value="1">
    <p class="account-title">Cambia password</p>
    <label for="current-password">Password attuale</label><input id="current-password" type="password" name="current_password" autocomplete="current-password" required>
    <label for="new-password">Nuova password (almeno 10 caratteri)</label><input id="new-password" type="password" name="new_password" autocomplete="new-password" minlength="10" required>
    <label for="new-password2">Ripeti la nuova password</label><input id="new-password2" type="password" name="new_password2" autocomplete="new-password" minlength="10" required>
    <button class="btn btn-white">Aggiorna password</button>
</form>
<p class="hint">Hai perso l'accesso e l'email non arriva? <?= h(KRIS_HOSTING_HELP) ?></p>
</div></section>
<section class="card" id="versione"><header class="card-header"><span class="collection-icon"><?= uiIcon('check') ?></span><div><h2>Versione di Kris</h2></div></header><div class="card-body">
<?php if ($stateError): ?><div class="alert alert-error" role="alert">Lo storico degli aggiornamenti non è leggibile (file <code>data/kris_state.json</code>). I contenuti non sono coinvolti: segnalalo a chi gestisce il sito.</div><?php endif; ?>
<div class="setting-row static"><span><strong>Versione installata</strong><small>Indicala quando chiedi assistenza.</small></span><span class="setting-value">Kris <?= h($krisVersion) ?></span></div>
<div class="setting-row static"><span><strong>Formato dei contenuti</strong><small>Cambia solo quando un aggiornamento converte i dati.</small></span><span class="setting-value"><?= isset($siteState['data_version']) ? h($siteState['data_version']) : 'Non ancora registrato' ?></span></div>
<div class="setting-row static"><span><strong>Ultimo controllo degli aggiornamenti</strong><?php if (!empty($lastCheck['error'])): ?><small>Non riuscito: <?= h($lastCheck['error']) ?></small><?php elseif (!empty($lastCheck['latest'])): ?><small>Era disponibile Kris <?= h($lastCheck['latest']) ?></small><?php endif; ?></span><span class="setting-value"><?= h(editorDateTime($lastCheck['at'] ?? null) ?? 'Mai') ?></span></div>
<div class="setting-row static"><span><strong>Ultimo aggiornamento</strong><?php if ($lastUpdate): ?><small>Da <?= h($field($lastUpdate, 'from') ?: '?') ?> a <?= h($field($lastUpdate, 'to') ?: '?') ?>, <?= h($outcomes[$field($lastUpdate, 'outcome')] ?? 'esito sconosciuto') ?></small><?php endif; ?></span><span class="setting-value"><?= $lastUpdate ? h(editorDateTime($lastUpdate['at'] ?? null) ?? 'Data sconosciuta') : 'Nessuno' ?></span></div>

<?php if ($maintenance !== null && $maintenance['stale']): ?>
<div class="update-panel">
    <div class="alert alert-error" role="alert"><p><strong>Un aggiornamento si è interrotto.</strong> Il sito potrebbe essere rimasto a metà. Ripristinando, torna esattamente com'era prima dell'aggiornamento.</p></div>
    <button type="button" class="btn btn-primary" data-update-recover>Ripristina il sito</button>
</div>
<?php elseif ($maintenance !== null): ?>
<div class="update-panel"><div class="notice"><strong>Aggiornamento in corso.</strong><p>Ricarica la pagina tra qualche minuto.</p></div></div>
<?php elseif (!$updater->enabled()): ?>
<div class="update-panel"><div class="notice"><strong>Aggiornamenti gestiti dallo sviluppatore.</strong><p>Su questo sito gli aggiornamenti di Kris non si installano dall'editor.</p></div></div>
<?php else: ?>
<div class="update-panel" data-update-panel data-current="<?= h($krisVersion) ?>">
    <div class="update-actions">
        <button type="button" class="btn btn-white" data-update-check>Verifica aggiornamenti</button>
        <span class="hint">Prima di ogni aggiornamento viene fatta una copia di sicurezza: se qualcosa non va, il sito torna com'era da solo.</span>
    </div>
    <div class="update-result" data-update-result aria-live="polite"></div>
    <details class="update-manual" data-update-manual>
        <summary>Carica un pacchetto a mano</summary>
        <p class="hint">Se l'hosting non riesce a scaricare gli aggiornamenti, scarica lo zip dalla pagina delle release di Kris e caricalo qui. La firma viene verificata prima di installare.</p>
        <div class="update-actions"><input type="file" accept=".zip,application/zip" data-update-file aria-label="Pacchetto zip di Kris"><button type="button" class="btn btn-white" data-update-upload>Carica e verifica</button></div>
    </details>
    <?php if ($rollback): ?>
    <div class="update-rollback">
        <div><strong>Torna a Kris <?= h($rollback['from']) ?></strong><small>Possibile fino al <?= h(editorDateTime($rollback['expires_at']) ?? '') ?>. Le modifiche ai contenuti fatte dopo l'aggiornamento del <?= h(editorDateTime($rollback['created_at']) ?? '') ?> andranno perse.</small></div>
        <button type="button" class="btn btn-white" data-update-rollback data-version="<?= h($rollback['from']) ?>">Torna alla versione precedente</button>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($history): ?>
<div class="update-history"><p class="nav-label">REGISTRO DEGLI AGGIORNAMENTI</p><ul>
<?php foreach ($history as $entry): ?><li><span><?= h(editorDateTime($entry['at'] ?? null) ?? '') ?></span><span>Da <?= h($field($entry, 'from') ?: '?') ?> a <?= h($field($entry, 'to') ?: '?') ?> · <?= h($outcomes[$field($entry, 'outcome')] ?? 'esito sconosciuto') ?><?= $field($entry, 'user') !== '' ? ' · ' . h($field($entry, 'user')) : '' ?></span><?php if ($field($entry, 'outcome') === 'rollback' && $field($entry, 'message') !== ''): ?><small><?= h($field($entry, 'message')) ?></small><?php endif; ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>
</div></section>
</div>

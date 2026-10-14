<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<?php
$staging = new \Kris\Staging\Staging();
$changes = $staging->changes();
$operations = $staging->rebuild();
$uploads = $staging->manifest()['uploads'];
$templateProblems = $staging->templateProblems();
$manifest = $staging->manifest();
$lastPromotion = $staging->lastPromotion();
$fileConflicts = array_filter($changes, fn($c) => $c['conflict']);
$opConflicts = array_filter($operations, fn($o) => $o['conflict'] !== null);
$blocked = $fileConflicts || $opConflicts || $templateProblems;
$hasChanges = $changes || $operations || $uploads;
$siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$previewUrl = $siteRoot . '/index.php?kris_preview=1';
$when = fn(string $iso): string => $iso === '' ? '' : date('d/m/Y H:i', strtotime($iso));
?>
<div class="container"><header class="page-heading"><div><h1>Modifiche in attesa</h1><p>Modifiche preparate e non ancora pubblicate. I visitatori vedono ancora il sito attuale.</p></div>
<?php if ($hasChanges): ?><div class="actions"><a class="btn btn-white" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Apri l'anteprima</a></div><?php endif; ?></header>

<?php if (!$hasChanges): ?>
    <section class="card"><div class="card-body"><p>Non ci sono modifiche in attesa.</p></div></section>
<?php else: ?>
    <p class="staging-origin">Preparate da <?= h($manifest['created_by'] ?: '—') ?><?= $manifest['created_at'] ? ' il ' . h($when($manifest['created_at'])) : '' ?>.</p>
    <?php if ($blocked): ?>
        <div class="alert alert-error" role="alert"><div><strong>Prima di pubblicare, controlla:</strong>
            <ul class="staging-problems">
                <?php foreach ($fileConflicts as $c): ?><li><?= h($c['file']) ?> è stato cambiato anche sul sito: pubblicando lo sostituisci.</li><?php endforeach; ?>
                <?php foreach ($opConflicts as $o): ?><li><?= h($o['conflict']) ?></li><?php endforeach; ?>
                <?php foreach ($templateProblems as $p): ?><li><?= h($p) ?>: dopo la pubblicazione lì comparirà un vuoto.</li><?php endforeach; ?>
            </ul></div></div>
    <?php endif; ?>

    <?php if ($operations): ?>
    <section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('content') ?></span><div><h2>Contenuti, struttura e pagine</h2><p><?= count($operations) ?> modifiche, applicate ai contenuti attuali del sito al momento della pubblicazione.</p></div></header>
    <div class="card-body"><ol class="staging-ops">
        <?php foreach ($operations as $o): ?>
            <li class="<?= $o['conflict'] !== null ? 'has-conflict' : '' ?>">
                <span><?= h($o['description']) ?></span>
                <?php if (($o['op']['op'] ?? '') === 'set_field' && is_string($o['op']['value'] ?? null)): ?><small class="staging-value"><?= h(mb_strimwidth(strip_tags($o['op']['value']), 0, 160, '…')) ?></small><?php endif; ?>
                <small><?= h((string) ($o['op']['by'] ?? '')) ?> · <?= h($when((string) ($o['op']['at'] ?? ''))) ?></small>
                <?php if ($o['conflict'] !== null): ?><span class="badge badge-danger">Non applicabile</span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol></div></section>
    <?php endif; ?>

    <?php if ($uploads): ?>
    <section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('media') ?></span><div><h2>Immagini caricate</h2><p>Finiranno nella libreria media con la pubblicazione.</p></div></header>
    <div class="card-body staging-uploads">
        <?php foreach ($uploads as $name => $info): $isPdf = str_ends_with((string) $name, '.pdf'); ?>
            <figure><?php if ($isPdf): ?><span class="file-placeholder">PDF</span><?php else: ?><img src="<?= h($siteRoot . '/index.php?kris_preview_asset=uploads/' . rawurlencode((string) $name)) ?>" alt="" loading="lazy"><?php endif; ?>
            <figcaption><?= h((string) ($info['original'] ?? $name)) ?></figcaption></figure>
        <?php endforeach; ?>
    </div></section>
    <?php endif; ?>

    <?php if ($changes): ?>
    <section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('structure') ?></span><div><h2>File del tema</h2><p><?= count($changes) ?> file da sostituire o aggiungere.</p></div></header>
    <div class="card-body staging-files">
    <?php foreach ($changes as $change): ?>
        <article class="staging-file">
            <header class="staging-file-head">
                <code><?= h($change['file']) ?></code>
                <span class="badge"><?= $change['kind'] === 'new' ? 'Nuovo' : 'Modificato' ?></span>
                <?php if ($change['conflict']): ?><span class="badge badge-danger">Cambiato anche sul sito</span><?php endif; ?>
                <small><?= h($change['changed_by']) ?> · <?= h($when($change['changed_at'])) ?></small>
            </header>
            <?php if ($change['sensitive']): ?>
                <div class="notice notice-warning"><strong>Da controllare prima di pubblicare</strong><p>Questa modifica aggiunge: <?= h(implode('; ', $change['sensitive'])) ?>.</p></div>
            <?php endif; ?>
            <pre class="diff-view"><?php foreach (\Kris\Staging\Diff::lines($change['site'] ?? '', $change['staged']) as [$op, $line]): ?><span class="diff-<?= ['+' => 'add', '-' => 'del', ' ' => 'same', '…' => 'gap'][$op] ?>"><?= $op === '…' ? '⋯' : h($op . ' ' . $line) ?></span>
<?php endforeach; ?></pre>
        </article>
    <?php endforeach; ?>
    </div></section>
    <?php endif; ?>

    <div class="staging-actions">
        <form method="POST" action="?action=staging" data-confirm-title="Pubblicare le modifiche?" data-confirm="<?= $blocked ? 'Ci sono avvisi da controllare: le modifiche dello staging prevarranno e quelle non applicabili verranno saltate. ' : '' ?>Le versioni attuali vengono salvate: potrai annullare la pubblicazione per <?= \Kris\Staging\Staging::BACKUP_DAYS ?> giorni." data-confirm-label="Pubblica">
            <input type="hidden" name="staging_promote" value="1"><?php if ($blocked): ?><input type="hidden" name="force" value="1"><?php endif; ?>
            <button class="btn btn-primary"><?= $blocked ? 'Pubblica comunque' : 'Pubblica sul sito' ?></button>
        </form>
        <form method="POST" action="?action=staging" data-confirm-title="Scartare le modifiche?" data-confirm="Le modifiche in attesa verranno eliminate. Il sito non cambia." data-confirm-label="Scarta">
            <input type="hidden" name="staging_discard" value="1"><button class="btn btn-danger">Scarta le modifiche</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($lastPromotion): ?>
    <section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('back') ?></span><div><h2>Ultima pubblicazione</h2><p><?= h($when((string) $lastPromotion['promoted_at'])) ?> da <?= h((string) $lastPromotion['promoted_by']) ?>: <?= h(implode(', ', array_keys($lastPromotion['files']))) ?></p></div></header>
    <div class="card-body"><form method="POST" action="?action=staging" data-confirm-title="Annullare la pubblicazione?" data-confirm="I file pubblicati, compresi contenuti e struttura, torneranno alla versione precedente. Le modifiche fatte dopo la pubblicazione a quei file andranno perse." data-confirm-label="Annulla pubblicazione">
        <input type="hidden" name="staging_undo" value="1"><button class="btn btn-white">Annulla la pubblicazione</button>
    </form></div></section>
<?php endif; ?>
</div>

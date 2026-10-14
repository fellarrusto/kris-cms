<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<?php
$staging = new \Kris\Staging\Staging();
$changes = $staging->changes();
$manifest = $staging->manifest();
$lastPromotion = $staging->lastPromotion();
$conflicts = array_filter($changes, fn($c) => $c['conflict']);
$siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$previewUrl = $siteRoot . '/index.php?kris_preview=1';
$when = fn(string $iso): string => $iso === '' ? '' : date('d/m/Y H:i', strtotime($iso));
?>
<div class="container"><header class="page-heading"><div><h1>Modifiche in attesa</h1><p>Modifiche al tema preparate e non ancora pubblicate. I visitatori vedono ancora il sito attuale.</p></div>
<?php if ($changes): ?><div class="actions"><a class="btn btn-white" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Apri l'anteprima</a></div><?php endif; ?></header>

<?php if (!$changes): ?>
    <section class="card"><div class="card-body"><p>Non ci sono modifiche in attesa.</p></div></section>
<?php else: ?>
    <?php if ($conflicts): ?>
        <div class="alert alert-error" role="alert">Alcuni file sono stati cambiati sul sito dopo che le modifiche sono state preparate: pubblicandole sostituisci quelle versioni. Controlla le differenze qui sotto.</div>
    <?php endif; ?>
    <section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('structure') ?></span><div><h2><?= count($changes) ?> file da pubblicare</h2><p>Preparate da <?= h($manifest['created_by'] ?: '—') ?><?= $manifest['created_at'] ? ' il ' . h($when($manifest['created_at'])) : '' ?>.</p></div></header>
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

    <div class="staging-actions">
        <form method="POST" action="?action=staging" data-confirm-title="Pubblicare le modifiche?" data-confirm="<?= count($changes) ?> file del sito verranno sostituiti. Le versioni attuali vengono salvate: potrai annullare la pubblicazione per <?= \Kris\Staging\Staging::BACKUP_DAYS ?> giorni." data-confirm-label="Pubblica">
            <input type="hidden" name="staging_promote" value="1"><?php if ($conflicts): ?><input type="hidden" name="force" value="1"><?php endif; ?>
            <button class="btn btn-primary"><?= $conflicts ? 'Pubblica comunque' : 'Pubblica sul sito' ?></button>
        </form>
        <form method="POST" action="?action=staging" data-confirm-title="Scartare le modifiche?" data-confirm="Le modifiche in attesa verranno eliminate. Il sito non cambia." data-confirm-label="Scarta">
            <input type="hidden" name="staging_discard" value="1"><button class="btn btn-danger">Scarta le modifiche</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($lastPromotion): ?>
    <section class="card"><header class="card-header"><span class="collection-icon"><?= uiIcon('back') ?></span><div><h2>Ultima pubblicazione</h2><p><?= h($when((string) $lastPromotion['promoted_at'])) ?> da <?= h((string) $lastPromotion['promoted_by']) ?>: <?= h(implode(', ', array_keys($lastPromotion['files']))) ?></p></div></header>
    <div class="card-body"><form method="POST" action="?action=staging" data-confirm-title="Annullare la pubblicazione?" data-confirm="I file pubblicati torneranno alla versione precedente. Le modifiche fatte a quei file dopo la pubblicazione andranno perse." data-confirm-label="Annulla pubblicazione">
        <input type="hidden" name="staging_undo" value="1"><button class="btn btn-white">Annulla la pubblicazione</button>
    </form></div></section>
<?php endif; ?>
</div>

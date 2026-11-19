<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
$staging = new \Kris\Staging\Staging();
$changes = $staging->changes();
$operations = $staging->rebuild();
$manifest = $staging->manifest();
$uploads = $manifest['uploads'];
$fonts = $manifest['fonts'];
$templateProblems = $staging->templateProblems();
$lastPromotion = $staging->lastPromotion();
$fileConflicts = array_filter($changes, fn($c) => $c['conflict']);
$opConflicts = array_filter($operations, fn($o) => $o['conflict'] !== null);
$blocked = $fileConflicts || $opConflicts || $templateProblems;
$hasChanges = $changes || $operations || $uploads || $fonts;
$siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
$previewUrl = $siteRoot . '/index.php?kris_preview=1';
$when = fn(string $iso): string => $iso === '' ? '' : (editorDateTime($iso) ?? '');
$codeFiles = $staging->codeFiles();
$codeApproved = $staging->codeApproved();
$total = count($operations) + count($changes) + count($uploads) + count($fonts);
$checks = count($fileConflicts) + count($opConflicts) + count($templateProblems) + ($codeFiles && !$codeApproved ? 1 : 0);
?>
<div class="container">
    <div class="page-head"><div><h1><?= $hasChanges ? $total . ($total === 1 ? ' modifica da rivedere' : ' modifiche da rivedere') : 'Niente da pubblicare' ?></h1><p class="sub"><?= $hasChanges ? 'Il sito pubblico non cambia finché non le pubblichi.' : 'Non ci sono modifiche in attesa.' ?></p></div><?php if ($hasChanges): ?><a class="btn btn-white" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener">Apri l'anteprima <?= uiIcon('external') ?></a><?php endif; ?></div>
    <div class="publish-cols">
    <div class="container">
    <?php if ($hasChanges): ?>
        <div class="box"><h2>Riepilogo</h2><p class="sub" style="font-size:14px">Preparate da <?= h($manifest['created_by'] ?: '—') ?><?= $manifest['created_at'] ? ', dal ' . h($when($manifest['created_at'])) : '' ?>.</p>
            <div class="stats"><div><span>Contenuti e struttura</span><b><?= count($operations) ?></b></div><div><span>File caricati</span><b><?= count($uploads) + count($fonts) ?></b></div><div><span>File del tema</span><b><?= count($changes) ?></b></div><div><span>Da controllare</span><b <?= $checks ? 'style="color:var(--warn-line)"' : '' ?>><?= $checks ?></b></div></div>
        </div>
        <?php if ($blocked): ?>
        <div class="notice notice-warning" role="alert"><strong>Prima di pubblicare, controlla</strong><ul class="staging-problems">
            <?php foreach ($fileConflicts as $c): ?><li><?= h($c['file']) ?> è stato cambiato anche sul sito: pubblicando lo sostituisci.</li><?php endforeach; ?>
            <?php foreach ($opConflicts as $o): ?><li><?= h($o['conflict']) ?> Pubblicando, verrà saltata.</li><?php endforeach; ?>
            <?php foreach ($templateProblems as $p): ?><li><?= h($p) ?>: dopo la pubblicazione lì comparirà un vuoto.</li><?php endforeach; ?>
        </ul></div>
        <?php endif; ?>
        <?php if ($codeFiles): ?>
        <div class="box code-approval">
        <?php if ($codeApproved): ?>
            <h2>Codice approvato</h2><p class="sub" style="font-size:14px">L'anteprima esegue il codice nuovo di <?= h(implode(', ', $codeFiles)) ?>. Se cambia, l'approvazione va rinnovata.</p>
        <?php else: ?>
            <h2>Codice da approvare</h2><p class="sub" style="font-size:14px;line-height:1.55">Queste modifiche aggiungono codice eseguito dal browser: <?= h(implode(', ', $codeFiles)) ?>. Gira con il tuo accesso all'editor aperto e potrebbe agire a nome tuo: finché non lo approvi, l'anteprima non lo esegue. Controllalo qui sotto, fra i file del tema.</p>
            <form method="POST" action="?action=staging" class="code-approval-form"><input type="hidden" name="approve_code" value="<?= h((string) $staging->codeFingerprint()) ?>">
                <label class="check-row"><input type="checkbox" required><span>Ho controllato il codice e me ne assumo la responsabilità.</span></label>
                <button class="btn btn-primary btn-small">Approva il codice</button>
            </form>
        <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php $previewPages = $staging->previewPages(); if ($previewPages): ?>
        <div class="box"><h2>Pagine da guardare</h2><p class="sub" style="font-size:14px">Le pagine che cambiano, ognuna con la sua anteprima: anche quelle che nessun link raggiunge.</p>
            <ul class="staging-problems"><?php foreach ($previewPages as $p): ?><li><a class="text-link" href="<?= h($siteRoot . '/' . $p['url']) ?>" target="_blank" rel="noopener"><?= h($p['label']) ?> <?= uiIcon('external') ?></a></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($operations): ?>
        <div class="box"><h2>Contenuti, struttura e pagine</h2><p class="sub" style="font-size:14px">Applicate ai contenuti del sito al momento della pubblicazione: le modifiche fatte intanto sul sito restano.</p>
            <ol class="staging-ops">
            <?php foreach ($operations as $o): ?>
                <li class="<?= $o['conflict'] !== null ? 'has-conflict' : '' ?>">
                    <span><?= h($o['description']) ?><?php if ($o['conflict'] !== null): ?> <span class="pill pill-warn">Non applicabile</span><?php endif; ?></span>
                    <?php if (($o['op']['op'] ?? '') === 'set_field' && is_string($o['op']['value'] ?? null) && trim(strip_tags($o['op']['value'])) !== ''): ?><span class="staging-value"><?= h(mb_strimwidth(strip_tags($o['op']['value']), 0, 160, '…')) ?></span><?php endif; ?>
                    <small><?= h((string) ($o['op']['by'] ?? '')) ?> · <?= h($when((string) ($o['op']['at'] ?? ''))) ?></small>
                </li>
            <?php endforeach; ?>
            </ol>
        </div>
        <?php endif; ?>
        <?php if ($uploads): ?>
        <div class="box"><h2>File caricati</h2><p class="sub" style="font-size:14px">Entrano nella libreria media con la pubblicazione.</p>
            <div class="card-body staging-uploads" style="margin-top:12px"><?php foreach ($uploads as $name => $info): ?><figure><?= editorMediaThumb($siteRoot . '/' . \Kris\Staging\Preview::assetUrl('uploads/' . rawurlencode((string) $name)), (string) $name) ?><figcaption><?= h((string) ($info['original'] ?? $name)) ?></figcaption></figure><?php endforeach; ?></div>
        </div>
        <?php endif; ?>
        <?php if ($fonts): ?>
        <div class="box"><h2>Font del tema</h2><p class="sub" style="font-size:14px">Vanno in assets/fonts/ con la pubblicazione; un font con lo stesso nome viene sostituito.</p>
            <ul class="staging-problems"><?php foreach ($fonts as $name => $info): ?><li><code>assets/fonts/<?= h((string) $name) ?></code> · <?= h((string) ($info['by'] ?? '')) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($changes): ?>
        <div class="box"><h2>File del tema</h2><p class="sub" style="font-size:14px"><?= count($changes) ?> <?= count($changes) === 1 ? 'file' : 'file' ?> da sostituire, aggiungere o eliminare.</p>
            <div class="card-body staging-files" style="margin-top:12px">
            <?php foreach ($changes as $change): ?>
                <article class="staging-file">
                    <header class="staging-file-head"><code><?= h($change['file']) ?></code><span class="pill <?= $change['kind'] === 'deleted' ? 'pill-danger' : '' ?>"><?= ['new' => 'Nuovo', 'modified' => 'Modificato', 'deleted' => 'Da eliminare'][$change['kind']] ?></span><?php if ($change['conflict']): ?><span class="pill pill-warn">Cambiato anche sul sito</span><?php endif; ?><small><?= h($change['changed_by']) ?> · <?= h($when($change['changed_at'])) ?></small></header>
                    <?php if ($change['sensitive']): ?><div class="notice notice-warning"><strong>Da controllare prima di pubblicare</strong><p>Questa modifica aggiunge: <?= h(implode('; ', $change['sensitive'])) ?>.</p></div><?php endif; ?>
                    <pre class="diff-view"><?php foreach (\Kris\Staging\Diff::lines($change['site'] ?? '', $change['staged']) as [$op, $line]): ?><span class="diff-<?= ['+' => 'add', '-' => 'del', ' ' => 'same', '…' => 'gap'][$op] ?>"><?= $op === '…' ? '⋯' : h($op . ' ' . $line) ?></span><?php endforeach; ?></pre>
                </article>
            <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="empty-state"><h2>Tutto pubblicato.</h2><p>Passa a «In attesa» per preparare modifiche da rivedere prima di pubblicarle.</p></div>
    <?php endif; ?>
    </div>
    <aside class="publish-side">
        <?php if ($hasChanges): ?>
        <div class="publish-box">
            <form method="POST" action="?action=staging" data-confirm-title="Pubblicare le modifiche?" data-confirm="<?= $blocked ? 'Ci sono avvisi: le modifiche non applicabili verranno saltate. ' : '' ?>Le versioni attuali vengono salvate: potrai annullare la pubblicazione per <?= \Kris\Staging\Staging::BACKUP_DAYS ?> giorni." data-confirm-label="Pubblica">
                <input type="hidden" name="staging_promote" value="1"><?php if ($blocked): ?><input type="hidden" name="force" value="1"><?php endif; ?>
                <button class="btn btn-pending"><?= $blocked ? 'Pubblica comunque' : 'Pubblica sul sito' ?></button>
            </form>
            <p><?= $opConflicts ? count($opConflicts) . (count($opConflicts) === 1 ? ' modifica non applicabile verrà saltata. ' : ' modifiche non applicabili verranno saltate. ') : '' ?><?= $codeFiles && !$codeApproved ? 'Il codice non approvato non gira nell\'anteprima, ma si pubblica. ' : '' ?>Potrai annullare la pubblicazione per <?= \Kris\Staging\Staging::BACKUP_DAYS ?> giorni.</p>
            <form method="POST" action="?action=staging" data-confirm-title="Scartare le modifiche?" data-confirm="Le modifiche in attesa verranno eliminate. Il sito non cambia." data-confirm-label="Scarta">
                <input type="hidden" name="staging_discard" value="1"><button class="btn">Scarta tutte le modifiche</button>
            </form>
        </div>
        <?php endif; ?>
        <?php if ($lastPromotion): ?>
        <div class="box"><h2>Ultima pubblicazione</h2>
            <p class="muted" style="margin:6px 0 12px"><?= h($when((string) $lastPromotion['promoted_at'])) ?> · <?= h((string) $lastPromotion['promoted_by']) ?><br><?= h(implode(', ', array_merge(array_keys($lastPromotion['files']), array_map([\Kris\Store\FileStore::class, 'relativePath'], array_keys(is_array($lastPromotion['documents'] ?? null) ? $lastPromotion['documents'] : []))))) ?></p>
            <form method="POST" action="?action=staging" data-confirm-title="Annullare la pubblicazione?" data-confirm="I file pubblicati, compresi contenuti e struttura, tornano alla versione precedente. Le modifiche fatte dopo su quei file andranno perse." data-confirm-label="Annulla pubblicazione">
                <input type="hidden" name="staging_undo" value="1"><button class="btn btn-white btn-small">Annulla la pubblicazione</button>
            </form>
        </div>
        <?php endif; ?>
    </aside>
    </div>
</div>

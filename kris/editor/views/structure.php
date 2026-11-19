<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
$typeNames = array_keys($userTypes);
if ($group === null || !isset($userTypes[$group])): ?>
<div class="container">
    <div class="page-head"><div><h1>Struttura</h1><p class="sub">I tipi di contenuto del sito e i loro campi. Qui si decide cosa si può modificare, non i testi.</p></div></div>
    <div class="structure-cols">
        <div class="rows">
            <?php foreach ($userTypes as $name => $type): $fieldCount = count(editorFields((string) $name)); ?>
            <div class="row"><a class="row-main" href="?action=structure&amp;group=<?= urlencode((string) $name) ?>"><span class="ic"><?= uiIcon(isSingleCollection((string) $name) ? 'page' : 'list') ?></span><span class="row-text"><span class="row-title"><?= h(collectionLabel((string) $name)) ?></span><span class="row-sub mono"><?= h((string) $name) ?> · <?= $fieldCount ?> <?= $fieldCount === 1 ? 'campo' : 'campi' ?> · <?= (int) ($counts[$name] ?? 0) ?> <?= ($counts[$name] ?? 0) === 1 ? 'contenuto' : 'contenuti' ?></span></span></a><span class="tag"><?= isSingleCollection((string) $name) ? 'Contenuto unico' : (isNewestFirst((string) $name) ? 'Articoli' : 'Raccolta') ?></span></div>
            <?php endforeach; ?>
            <?php if (!$userTypes): ?><p class="muted" style="padding:16px">Ancora nessun tipo: creane uno qui accanto.</p><?php endif; ?>
        </div>
        <form method="POST" class="box collection-info-form">
            <h2>Nuova raccolta</h2>
            <label class="field-label">Nome visibile<input class="input" type="text" name="collection_label" maxlength="60" placeholder="Es. Servizi" data-slug-source></label>
            <label class="field-label">Nome tecnico<input class="input mono" type="text" name="collection_name" pattern="[a-z][a-z0-9_]*" placeholder="servizi" required data-slug-target></label>
            <p class="hint">Lo usano i template. Lettere minuscole, numeri e _; non si cambia dopo.</p>
            <label class="check-row"><input type="checkbox" name="collection_single" value="1"><span><strong>Contenuto unico</strong><small>Per una pagina o un blocco condiviso: si apre direttamente la modifica.</small></span></label>
            <button class="btn btn-primary" name="create_collection" value="1"><?= uiIcon('plus') ?>Crea e scegli i campi</button>
        </form>
    </div>
</div>
<?php return; endif;

$fields = editorFields($group);
$displaySchema = $fields;
if ($error && isset($_POST['schema_json'])) {
    // Dopo un errore si mostra quello che era stato inviato, non lo schema salvato
    $attempted = json_decode((string) $_POST['schema_json'], true);
    if (is_array($attempted) && array_is_list($attempted)) $displaySchema = editorCleanSchema($attempted);
}
$entityCount = (int) ($counts[$group] ?? 0);
$position = (int) array_search($group, $typeNames, true);
$pages = array_filter(editorSite()['schema']['pages'], fn($p) => is_array($p) && ($p['type'] ?? null) === $group);
?>
<div class="container">
    <div class="page-head"><div><p class="kicker"><a href="?action=structure">Struttura</a></p><h1><?= h(collectionLabel($group)) ?></h1><p class="sub mono"><?= h($group) ?> · <?= count($fields) ?> <?= count($fields) === 1 ? 'campo' : 'campi' ?> · <?= $entityCount ?> <?= $entityCount === 1 ? 'contenuto' : 'contenuti' ?></p></div><a class="btn btn-white" href="?action=list&amp;group=<?= urlencode($group) ?>">Apri i contenuti</a></div>
    <div class="structure-cols">
        <form method="POST" id="structureForm" class="container" data-dirty-form>
            <input type="hidden" name="save_structure" value="1"><input type="hidden" name="group_name" value="<?= h($group) ?>"><input type="hidden" name="schema_json" id="schema_json">
            <div id="root-schema" class="sf-container"><?php renderSchemaRoot($displaySchema); ?></div>
            <div class="sf-actions"><button type="button" class="btn btn-white" data-add-root><?= uiIcon('plus') ?>Aggiungi campo</button><button type="button" class="btn btn-white" data-add-section><?= uiIcon('plus') ?>Aggiungi sezione</button></div>
            <p class="hint">Togliere un campo o cambiarne il tipo può cancellare testi già scritti: prima di salvare vedrai cosa si perde.</p>
            <?php require __DIR__ . '/../partials/save_bar.php'; ?>
        </form>
        <aside class="structure-side">
            <form method="POST" class="box collection-info-form">
                <input type="hidden" name="save_collection_info" value="1"><input type="hidden" name="group_name" value="<?= h($group) ?>">
                <h2>Nome e posizione</h2>
                <label class="field-label">Nome visibile<input class="input" type="text" name="collection_label" maxlength="60" value="<?= h((string) (editorType($group)['label'] ?? '')) ?>" placeholder="<?= h(editorLabel($group)) ?>"></label>
                <label class="field-label">Descrizione<input class="input" type="text" name="collection_description" maxlength="200" value="<?= h(collectionDescription($group)) ?>" placeholder="Una riga su cosa contiene"></label>
                <label class="field-label">Posizione fra le raccolte<select class="input" name="collection_position"><?php foreach ($typeNames as $i => $_): ?><option value="<?= $i ?>" <?= $i === $position ? 'selected' : '' ?>><?= $i + 1 ?></option><?php endforeach; ?></select></label>
                <label class="check-row"><input type="checkbox" name="collection_single" value="1" <?= isSingleCollection($group) ? 'checked' : '' ?> <?= $entityCount > 1 ? 'disabled' : '' ?>><span><strong>Contenuto unico</strong><small><?= $entityCount > 1 ? 'Possibile solo con un contenuto: questa raccolta ne ha ' . $entityCount . '.' : 'Si apre direttamente la modifica, senza lista, nuovo contenuto né eliminazione.' ?></small></span></label>
                <button class="btn btn-white btn-small">Salva</button>
            </form>
            <?php if (!isSingleCollection($group)): ?>
            <form method="POST" class="box"><input type="hidden" name="toggle_post_collection" value="1"><input type="hidden" name="group_name" value="<?= h($group) ?>"><input type="hidden" name="enabled" value="<?= isNewestFirst($group) ? '0' : '1' ?>">
                <h2>I nuovi in cima</h2>
                <p class="muted"><?= isNewestFirst($group) ? 'I nuovi contenuti entrano in cima e la raccolta ha la sua voce nella barra laterale: va bene per articoli, notizie, eventi.' : 'Per articoli, notizie o eventi: i nuovi contenuti entrano in cima e la raccolta ha la sua voce nella barra laterale.' ?></p>
                <button class="btn btn-white btn-small" style="margin-top:12px"><?= isNewestFirst($group) ? 'Disattiva' : 'Attiva' ?></button>
            </form>
            <?php endif; ?>
            <div class="box"><h2>Pagine</h2>
                <?php if ($pages): ?><p class="mono" style="margin-top:8px;line-height:1.8"><?php foreach ($pages as $page => $p): ?><?= h((string) $page) ?> → template/<?= h((string) ($p['template'] ?? $page)) ?>.html<?= isset($p['item']) ? '' : ' (una per contenuto)' ?><br><?php endforeach; ?></p>
                <?php else: ?><p class="muted" style="margin-top:6px">Nessuna pagina del sito rende questo tipo da sola: lo usano i template delle altre pagine.</p><?php endif; ?>
            </div>
            <div class="danger-zone">
                <h2>Elimina il tipo</h2>
                <p>Con i suoi <?= $entityCount ?> <?= $entityCount === 1 ? 'contenuto' : 'contenuti' ?><?= $pages ? ' e le sue pagine' : '' ?>. Si annulla solo ripristinando una copia di sicurezza.</p>
                <form method="POST" data-confirm-title="Eliminare «<?= h(collectionLabel($group)) ?>»?" data-confirm="Verranno eliminati il tipo e <?= $entityCount ?> contenuti, con i loro elementi<?= $pages ? ' e le pagine che lo rendono' : '' ?>." data-confirm-label="Elimina tipo"><input type="hidden" name="delete_collection" value="1"><input type="hidden" name="group_name" value="<?= h($group) ?>"><button class="btn btn-danger btn-small">Elimina tipo</button></form>
            </div>
        </aside>
    </div>
</div>

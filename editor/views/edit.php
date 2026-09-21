<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
$id = (int) ($_GET['id'] ?? 0);
$pathRaw = (string) ($_GET['path'] ?? '');
$path = parsePath($pathRaw);
$rootIdx = findRootIndex($data, $group, $id);
$rootEntity = $rootIdx >= 0 ? $data[$rootIdx] : null;
$entity = null;
if ($rootEntity !== null && count($path) % 2 === 0) {
    $copy = $rootEntity;
    $target = &walkEntityPath($copy, $path);
    if ($target !== null && isset($target['data'])) $entity = $target;
    unset($target);
}
$schema = resolveSchemaAtPath($models, $group, $path);
$values = $entity ? array_column($entity['data'], 'value', 'name') : [];
$editUrl = '?action=edit&group=' . urlencode($group) . '&id=' . $id;
$backPath = array_slice($path, 0, -2);
$backHref = $path ? $editUrl . ($backPath ? '&path=' . urlencode(pathToString($backPath)) : '') : '?action=list&group=' . urlencode($group);
$title = $entity ? editorTitle($entity) : 'Contenuto non disponibile';
$firstLang = $activeLangs[0] ?? 'it';
?>
<div class="container">
    <header class="page-heading"><div><p class="eyebrow"><?= h(editorLabel($group)) ?><?= $path ? ' / ' . h(editorLabel($path[count($path)-2])) : '' ?></p><h1><?= h($title) ?></h1><p><?= $path ? 'Modifica questo elemento, poi torna al contenuto principale.' : 'Dai voce al tuo sito, un dettaglio alla volta.' ?></p></div><a class="btn btn-white" href="<?= h($backHref) ?>"><?= uiIcon('back') ?><?= $path ? 'Torna al contenuto' : 'Torna alla raccolta' ?></a></header>
    <?php if ($path): ?><nav class="nested-breadcrumb" aria-label="Contenuto annidato"><a href="<?= h($editUrl) ?>"><?= h(editorTitle($rootEntity)) ?></a><?php for ($i=0; $i<count($path); $i+=2): ?><span aria-hidden="true">/</span><a href="<?= h($editUrl . '&path=' . urlencode(pathToString(array_slice($path,0,$i+2)))) ?>"><?= h(editorLabel($path[$i])) ?> #<?= (int) $path[$i+1] ?></a><?php endfor; ?></nav><?php endif; ?>
    <?php if ($entity === null): ?><div class="empty-state"><h2>Questo contenuto non è più disponibile.</h2><p>Potrebbe essere stato rimosso. Torna alla raccolta e scegli un altro elemento.</p><a class="btn btn-primary" href="?action=list&amp;group=<?= urlencode($group) ?>">Apri raccolta</a></div><?php else: ?>
    <div class="edit-layout"><div class="edit-content">
    <form method="POST" id="contentForm" data-dirty-form data-async-save>
        <input type="hidden" name="save_entity" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($pathRaw) ?>">
        <div class="language-toolbar"><div><strong>Lingua di modifica</strong><small>I campi non tradotti e le liste sono condivisi.</small></div><div class="language-switch" role="group" aria-label="Lingua di modifica"><?php foreach ($activeLangs as $i => $lang): ?><button type="button" data-editor-language="<?= h($lang) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>" class="<?= $i === 0 ? 'active' : '' ?>"><?= h($DEFAULT_LANGS[$lang] ?? strtoupper($lang)) ?></button><?php endforeach; ?></div></div>
        <?php foreach ($schema as $fieldIndex => $f):
            $n = $f['name']; $t = $f['type']; $saved = $values[$n] ?? null;
            $label = $f['label'] ?? $f['description'] ?? editorLabel($n);
            $fieldId = 'field-' . $fieldIndex;
        ?>
        <section class="card field-card" id="<?= $fieldId ?>"><header class="card-header"><span class="section-number"><?= str_pad((string) ($fieldIndex+1), 2, '0', STR_PAD_LEFT) ?></span><div><h2><?= h($label) ?></h2><p><?= h($n) ?> <span aria-hidden="true">&middot;</span> <?= h(['plain'=>'Testo condiviso','text'=>'Testo multilingua','richtext'=>'Testo formattato','image'=>'Media multilingua','array'=>'Elenco di contenuti'][$t] ?? $t) ?></p></div></header><div class="card-body">
        <?php if ($t === 'array'):
            $children = is_array($saved) ? $saved : [];
            $prefix = $pathRaw === '' ? $n : $pathRaw . '/' . $n;
        ?>
            <div class="nested-heading"><span class="badge"><?= count($children) ?> elementi</span><button type="submit" form="create_nested_<?= h($n) ?>" class="btn btn-white"><?= uiIcon('plus') ?>Aggiungi elemento</button></div>
            <?php if (!$children): ?><p class="empty-inline">La lista è vuota. Aggiungi il primo elemento per iniziare.</p><?php else: ?>
            <div class="table-scroll"><table class="dnd-table nested-table" data-dnd-scope="nested" data-reorder-form="reorder_nested_<?= h($n) ?>"><thead><tr><th>Contenuto</th><th>Ordine</th><th><span class="sr-only">Azioni</span></th></tr></thead><tbody>
            <?php foreach ($children as $childIndex => $ch): $chId = (int) ($ch['id'] ?? 0); $childTitle = editorTitle($ch); ?>
                <tr draggable="true" data-id="<?= $chId ?>"><td><strong><?= h($childTitle) ?></strong><small>Elemento #<?= $chId ?></small></td><td><div class="order-actions"><button type="button" class="icon-button" data-move="-1" aria-label="Sposta <?= h($childTitle) ?> in alto" <?= $childIndex === 0 ? 'disabled' : '' ?>><?= uiIcon('up') ?></button><button type="button" class="icon-button" data-move="1" aria-label="Sposta <?= h($childTitle) ?> in basso" <?= $childIndex === count($children)-1 ? 'disabled' : '' ?>><?= uiIcon('down') ?></button></div></td><td><div class="row-actions"><a class="btn btn-white" href="<?= h($editUrl . '&path=' . urlencode($prefix . '/' . $chId)) ?>">Modifica</a><button type="submit" form="del_nested_<?= h($n) ?>_<?= $chId ?>" class="icon-button danger" aria-label="Elimina <?= h($childTitle) ?>"><?= uiIcon('trash') ?></button></div></td></tr>
            <?php endforeach; ?></tbody></table></div><?php endif; ?>
            <p class="hint">Aggiunta, eliminazione e ordine si salvano subito. Se hai modificato i testi, potrai salvarli prima di continuare.</p>
        <?php elseif ($t === 'plain'): ?>
            <label class="sr-only" for="input-<?= $fieldIndex ?>"><?= h($label) ?></label><textarea id="input-<?= $fieldIndex ?>" name="f[<?= h($n) ?>][val]" rows="3"><?= h(is_array($saved) ? '' : $saved) ?></textarea>
        <?php else: foreach ($activeLangs as $i => $lang): $v = is_array($saved) ? ($saved[$lang] ?? '') : ''; $inputId = 'input-' . $fieldIndex . '-' . $lang; ?>
            <div class="localized-field" data-language-panel="<?= h($lang) ?>" <?= $i ? 'hidden' : '' ?>>
            <label class="sr-only" for="<?= $inputId ?>"><?= h($label . ' · ' . ($DEFAULT_LANGS[$lang] ?? $lang)) ?></label>
            <?php if ($t === 'image'): ?>
                <div class="media-field"><div class="media-preview" data-preview-for="<?= $inputId ?>"><?php $src = editorMediaSource((string) $v); if ($src && strtolower(pathinfo((string) parse_url($src, PHP_URL_PATH), PATHINFO_EXTENSION)) !== 'pdf'): ?><img src="<?= h($src) ?>" alt="Anteprima di <?= h($label) ?>"><?php else: ?><?= uiIcon('media') ?><?php endif; ?></div><div class="media-field-controls"><input type="text" id="<?= $inputId ?>" name="f[<?= h($n) ?>][<?= h($lang) ?>]" value="<?= h($v) ?>" placeholder="URL del file" data-media-input><button type="button" class="btn btn-white" data-pick-media="<?= $inputId ?>"><?= uiIcon('media') ?>Scegli dalla libreria</button></div></div>
            <?php else: ?><textarea id="<?= $inputId ?>" name="f[<?= h($n) ?>][<?= h($lang) ?>]" rows="<?= $t === 'richtext' ? 8 : 3 ?>" <?= $t === 'richtext' ? 'class="richtext"' : '' ?>><?= h($v) ?></textarea><?php endif; ?>
            </div>
        <?php endforeach; endif; ?>
        </div></section>
        <?php endforeach; ?>
        <?php if (!$schema): ?><div class="empty-state"><h2>Non ci sono campi da compilare.</h2><a href="?action=structure&amp;group=<?= urlencode($group) ?>" class="btn btn-primary">Configura la struttura</a></div><?php endif; ?>
        <?php require __DIR__ . '/../partials/save_bar.php'; ?>
    </form>
    <?php foreach ($schema as $f): if (($f['type'] ?? '') !== 'array') continue;
        $n=$f['name']; $children=is_array($values[$n] ?? null) ? $values[$n] : [];
        $prefix=$pathRaw === '' ? $n : $pathRaw . '/' . $n;
    ?>
    <form method="POST" id="create_nested_<?= h($n) ?>" hidden><input type="hidden" name="create_nested" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($prefix) ?>"></form>
    <form method="POST" action="<?= h($BASE) ?>" id="reorder_nested_<?= h($n) ?>" hidden><input type="hidden" name="reorder_nested" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($prefix) ?>"><input type="hidden" name="order" value=""></form>
    <?php foreach ($children as $ch): $chId=(int) $ch['id']; ?><form method="POST" id="del_nested_<?= h($n) ?>_<?= $chId ?>" hidden data-confirm-title="Eliminare questo elemento?" data-confirm="&ldquo;<?= h(editorTitle($ch)) ?>&rdquo; e i suoi sotto-elementi verranno eliminati subito. Non puoi annullare dall’editor." data-confirm-label="Elimina elemento"><input type="hidden" name="delete_nested" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($prefix . '/' . $chId) ?>"></form><?php endforeach; ?>
    <?php endforeach; ?>
    </div><aside class="context-panel"><p class="eyebrow">IN QUESTA PAGINA</p><nav aria-label="Campi del contenuto"><?php foreach ($schema as $i => $f): ?><a href="#field-<?= $i ?>"><span><?= str_pad((string) ($i+1),2,'0',STR_PAD_LEFT) ?></span><?= h($f['label'] ?? editorLabel($f['name'])) ?></a><?php endforeach; ?></nav><div class="context-note"><strong>Salva quando sei pronto.</strong><p>I contenuti salvati sono subito visibili sul sito. “Apri il sito” mostra la versione salvata.</p></div><a class="text-link" href="?action=structure&amp;group=<?= urlencode($group) ?>"><?= uiIcon('structure') ?>Configura i campi</a></aside>
    </div><?php endif; ?>
</div>

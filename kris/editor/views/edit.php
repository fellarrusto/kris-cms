<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
$id = (int) ($_GET['id'] ?? 0);
$pathRaw = is_string($_GET['path'] ?? null) ? $_GET['path'] : '';
$path = parsePath($pathRaw);
$node = count($path) % 2 === 0 ? editorNode($group, $id, $path) : null;
$root = editorItem($group, $id);
$fields = editorFields($group, editorListNames($path));
$values = is_array($node['values'] ?? null) ? $node['values'] : [];
$editUrl = '?action=edit&group=' . urlencode($group) . '&id=' . $id;
$reserved = isReservedCollection($group);
$title = editorTitle($node, $fields);
$kicker = collectionLabel($group);
$backHref = '?action=list&group=' . urlencode($group);
$backLabel = 'Torna a ' . collectionLabel($group);
if ($path) {
    $parentPath = array_slice($path, 0, -2);
    $listDef = editorFieldDef(editorFields($group, editorListNames($parentPath)), (string) $path[count($path) - 2]);
    $kicker = collectionLabel($group) . ' / ' . editorFieldLabel($listDef ?? ['name' => (string) $path[count($path) - 2]]);
    $backHref = $editUrl . ($parentPath ? '&path=' . urlencode(pathToString($parentPath)) : '') . '#list-' . $path[count($path) - 2];
    $backLabel = 'Torna al contenuto';
} elseif ($reserved || isSingleCollection($group)) {
    // Un contenuto unico porta il nome della raccolta e non ha una lista
    $title = $reserved ? ($reservedViews[$group] ?? collectionLabel($group)) : collectionLabel($group);
    $kicker = $reserved ? 'Sito' : 'Pagine e blocchi';
    $backHref = '?action=dashboard';
    $backLabel = 'Torna ai contenuti';
}
$intro = [
    'kris_site' => 'Icona, nome, titolo e condivisione del sito, codici di verifica dei motori di ricerca. Kris li aggiunge a ogni pagina solo dove il template non li ha già.',
    'kris_legal' => 'Dati del titolare, informative e testi dell\'avviso sui cookie. Il sito li mostra dove i suoi template li usano.',
    'kris_notfound' => 'Quello che vede chi apre un indirizzo che non esiste. Se il tema ha un suo 404.html usa questi testi, altrimenti li usa la pagina di serie di Kris.',
    'kris_wip' => 'Con l\'interruttore acceso i visitatori vedono solo questa pagina (stato 503: i motori di ricerca tornano più tardi). Tu, con l\'editor aperto, continui a vedere il sito.',
][$group] ?? '';
// Si sospende un elemento di un elenco o un contenuto fra tanti; non l'unico contenuto né le sezioni di Kris
$canHide = $node !== null && !$reserved && ($path || count(editorItems($group)) > 1);
$langNames = $DEFAULT_LANGS;
$localized = array_filter($fields, fn($f) => ($f['type'] ?? '') !== 'list' && !empty($f['localized']));
// Lingue con testi mancanti: campi compilati nella lingua principale e vuoti in quella
$missing = [];
foreach (array_slice($activeLangs, 1) as $lang) {
    $missing[$lang] = count(array_filter($localized, function ($f) use ($values, $activeLangs, $lang) {
        $v = $values[$f['name']] ?? null;
        return in_array($f['type'], ['text', 'richtext'], true) && is_array($v)
            && trim((string) ($v[$activeLangs[0]] ?? '')) !== '' && trim((string) ($v[$lang] ?? '')) === '';
    }));
}
$sections = schemaSections($fields);
$openSection = $sections && $sections[0]['section'] !== '' ? 0 : 1;
$typeLabel = fn(array $f): string => ($f['type'] ?? '') === 'list' ? 'Elenco' : (!empty($f['localized']) ? 'Per lingua' : 'Uguale in tutte le lingue');
?>
<div class="container">
    <div class="edit-head">
        <a class="back" href="<?= h($backHref) ?>" aria-label="<?= h($backLabel) ?>"><?= uiIcon('back') ?></a>
        <div class="edit-head-text"><p class="kicker"><?= h($kicker) ?></p><h1><?= h($title) ?></h1></div>
        <div class="edit-head-tools">
            <?php if ($node !== null && $localized && count($activeLangs) > 1): ?>
            <div class="lang-switch" role="group" aria-label="Lingua di modifica"><?php foreach ($activeLangs as $i => $lang): ?><button type="button" data-editor-language="<?= h($lang) ?>" aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"><?= h($langNames[$lang] ?? strtoupper($lang)) ?><?php if (!empty($missing[$lang])): ?><span class="pill pill-danger"><?= $missing[$lang] ?> <?= $missing[$lang] === 1 ? 'vuoto' : 'vuoti' ?></span><?php endif; ?></button><?php endforeach; ?></div>
            <?php endif; ?>
            <?php if ($canHide): ?><?= visibilityToggle('vis_current', !isHiddenItem($node), $title, true) ?><?= visibilityForm('vis_current', $group, $id, $pathRaw) ?><?php endif; ?>
        </div>
    </div>
    <?php if ($intro !== ''): ?><p class="sub"><?= h($intro) ?></p><?php endif; ?>
    <?php if ($path && $root !== null): ?><nav class="nested-crumb" aria-label="Contenuto annidato"><a href="<?= h($editUrl) ?>"><?= h(editorTitle($root, editorFields($group))) ?></a><?php for ($i = 0; $i < count($path); $i += 2): $sub = editorNode($group, $id, array_slice($path, 0, $i + 2)); ?><span aria-hidden="true">/</span><a href="<?= h($editUrl . '&path=' . urlencode(pathToString(array_slice($path, 0, $i + 2)))) ?>"><?= h(editorTitle($sub, editorFields($group, editorListNames(array_slice($path, 0, $i + 1))))) ?></a><?php endfor; ?></nav><?php endif; ?>

    <?php if ($node === null): ?>
    <div class="empty-state"><h2>Questo contenuto non è più disponibile.</h2><p>Potrebbe essere stato eliminato. Torna alla raccolta e scegli un altro contenuto.</p><a class="btn btn-primary" href="?action=list&amp;group=<?= urlencode($group) ?>">Apri la raccolta</a></div>
    <?php elseif (!$fields): ?>
    <div class="empty-state"><h2>Non ci sono campi da compilare.</h2><?php if (!$stagingMode && !$reserved): ?><a href="?action=structure&amp;group=<?= urlencode($group) ?>" class="btn btn-primary">Scegli i campi in Struttura</a><?php endif; ?></div>
    <?php else: ?>
    <div class="edit-cols">
        <form method="POST" id="contentForm" class="panel" data-dirty-form data-async-save>
            <input type="hidden" name="save_entity" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($pathRaw) ?>">
            <?php foreach ($sections as $sectionIndex => $section):
                $inPanel = $section['section'] !== '';
                if ($inPanel): ?><details class="field-section" id="section-<?= $sectionIndex ?>" <?= $sectionIndex === $openSection ? 'open' : '' ?>><summary><?= h($section['section']) ?> <span class="count"><?= count($section['fields']) ?> <?= count($section['fields']) === 1 ? 'campo' : 'campi' ?></span></summary><?php endif;
                foreach ($section['fields'] as $fieldIndex => $f):
                    $n = $f['name']; $t = $f['type']; $saved = $values[$n] ?? null;
                    $label = editorFieldLabel($f);
                    $inputBase = 'input-' . $fieldIndex; ?>
                <div class="field" id="field-<?= $fieldIndex ?>">
                    <div class="field-head"><?php if (in_array($t, ['list', 'media', 'richtext'], true) || ($t === 'select' && !empty($f['multiple']))): ?><span><?= h($label) ?></span><?php else: ?><label for="<?= h($inputBase . (empty($f['localized']) ? '' : '-' . $activeLangs[0])) ?>"><?= h($label) ?></label><?php endif; ?><span class="scope"><?= h($typeLabel($f)) ?></span><span class="field-tech"><?= h($n) ?></span></div>
                    <?php if ($t === 'list'):
                        $children = array_values(array_filter(is_array($saved) ? $saved : [], 'is_array'));
                        $childFields = editorFields($group, [...editorListNames($path), $n]);
                        $prefix = $pathRaw === '' ? $n : $pathRaw . '/' . $n; ?>
                        <div class="list-field" id="list-<?= h($n) ?>">
                            <?php if ($children): ?>
                            <div class="rows" data-dnd-list data-reorder-form="reorder_nested_<?= h($n) ?>">
                            <?php foreach ($children as $child): $childId = (int) ($child['id'] ?? 0); $childTitle = editorTitle($child, $childFields); $childHidden = isHiddenItem($child); ?>
                                <div class="row<?= $childHidden ? ' is-hidden' : '' ?>" id="item-<?= h($n) ?>-<?= $childId ?>" data-id="<?= $childId ?>">
                                    <a class="row-main" href="<?= h($editUrl . '&path=' . urlencode($prefix . '/' . $childId)) ?>"><span class="row-text"><span class="row-title"><?= h($childTitle) ?></span></span></a>
                                    <div class="row-actions">
                                        <?php if ($childHidden): ?><span class="pill">Sospeso</span><?php endif; ?>
                                        <?= visibilityToggle('vis_' . $n . '_' . $childId, !$childHidden, $childTitle) ?>
                                        <button type="submit" form="del_nested_<?= h($n) ?>_<?= $childId ?>" class="icon-button danger" aria-label="Elimina <?= h($childTitle) ?>"><?= uiIcon('trash') ?></button>
                                        <button type="button" class="icon-button handle" data-handle aria-label="Sposta <?= h($childTitle) ?>: trascina, o usa le frecce su e giù"><?= uiIcon('drag') ?></button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            </div>
                            <?php else: ?><p class="muted">Ancora nessun elemento.</p><?php endif; ?>
                            <div class="bar"><button type="submit" form="create_nested_<?= h($n) ?>" class="btn btn-white btn-small"><?= uiIcon('plus') ?>Aggiungi elemento</button><span class="muted">Aggiunta, eliminazione e ordine si salvano subito.</span></div>
                        </div>
                    <?php elseif (in_array($t, ['boolean', 'select'], true) && empty($f['localized'])): ?>
                        <?= editorChoiceInput($f, 'f[' . $n . '][val]', $inputBase, $label, $saved) ?>
                    <?php elseif (empty($f['localized'])): ?>
                        <?php if ($t === 'media'): $v = is_string($saved) ? $saved : ''; ?>
                            <div class="media-field"><div class="media-preview" data-preview-for="<?= h($inputBase) ?>"><?php $src = editorMediaSource($v); ?><?= $src !== '' ? editorMediaThumb($src, $v, 'Anteprima di ' . $label) : 'Nessun file' ?></div><div class="media-field-controls"><input class="input" type="text" id="<?= h($inputBase) ?>" name="f[<?= h($n) ?>][val]" value="<?= h($v) ?>" placeholder="assets/uploads/…" aria-label="<?= h($label) ?>: indirizzo del file" data-media-input><button type="button" class="btn btn-white btn-small" data-pick-media="<?= h($inputBase) ?>">Scegli</button><button type="button" class="btn btn-white btn-small" data-clear-media="<?= h($inputBase) ?>">Togli</button></div></div>
                        <?php else: ?>
                            <textarea class="input<?= $t === 'richtext' ? ' richtext' : ' autogrow' ?>" id="<?= h($inputBase) ?>" name="f[<?= h($n) ?>][val]" rows="<?= $t === 'richtext' ? 8 : 1 ?>"><?= h(is_string($saved) ? $saved : '') ?></textarea>
                        <?php endif; ?>
                    <?php else: foreach ($activeLangs as $i => $lang):
                        $v = is_array($saved) ? ($saved[$lang] ?? null) : null;
                        $inputId = $inputBase . '-' . $lang;
                        $langLabel = $label . ' · ' . ($langNames[$lang] ?? $lang); ?>
                        <div class="localized-field" data-language-panel="<?= h($lang) ?>" <?= $i ? 'hidden' : '' ?>>
                        <?php if (in_array($t, ['boolean', 'select'], true)): ?>
                            <?= editorChoiceInput($f, 'f[' . $n . '][' . $lang . ']', $inputId, $langLabel, $v) ?>
                        <?php elseif ($t === 'media'): $v = is_string($v) ? $v : ''; $src = editorMediaSource($v); ?>
                            <div class="media-field"><div class="media-preview" data-preview-for="<?= h($inputId) ?>"><?= $src !== '' ? editorMediaThumb($src, $v, 'Anteprima di ' . $label) : 'Nessun file' ?></div><div class="media-field-controls"><input class="input" type="text" id="<?= h($inputId) ?>" name="f[<?= h($n) ?>][<?= h($lang) ?>]" value="<?= h($v) ?>" placeholder="assets/uploads/…" aria-label="<?= h($langLabel) ?>: indirizzo del file" data-media-input><button type="button" class="btn btn-white btn-small" data-pick-media="<?= h($inputId) ?>">Scegli</button><button type="button" class="btn btn-white btn-small" data-clear-media="<?= h($inputId) ?>">Togli</button></div></div>
                        <?php else: ?>
                            <textarea class="input<?= $t === 'richtext' ? ' richtext' : ' autogrow' ?>" id="<?= h($inputId) ?>" name="f[<?= h($n) ?>][<?= h($lang) ?>]" rows="<?= $t === 'richtext' ? 8 : 1 ?>" <?= $t === 'richtext' ? 'aria-label="' . h($langLabel) . '"' : '' ?>><?= h(is_string($v) ? $v : '') ?></textarea>
                        <?php endif; ?>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <?php endforeach;
                if ($inPanel): ?></details><?php endif;
            endforeach; ?>
            <?php require __DIR__ . '/../partials/save_bar.php'; ?>
        </form>
        <nav class="index" aria-label="Campi del contenuto">
            <p>In questa pagina</p>
            <?php foreach ($sections as $sectionIndex => $section): ?>
                <?php if ($section['section'] !== ''): ?><a href="#section-<?= $sectionIndex ?>"><?= h($section['section']) ?> <span class="mono"><?= count($section['fields']) ?></span></a>
                <?php else: foreach ($section['fields'] as $i => $f): ?><a href="#field-<?= $i ?>"><?= h(editorFieldLabel($f)) ?></a><?php endforeach; endif; ?>
            <?php endforeach; ?>
            <p class="note"><?= $stagingMode ? 'Salvando, le modifiche restano in attesa: il sito cambia quando pubblichi.' : 'Salvando, le modifiche sono subito visibili sul sito.' ?></p>
        </nav>
    </div>
    <?php foreach ($fields as $f): if (($f['type'] ?? '') !== 'list') continue;
        $n = $f['name'];
        $children = array_values(array_filter(is_array($values[$n] ?? null) ? $values[$n] : [], 'is_array'));
        $childFields = editorFields($group, [...editorListNames($path), $n]);
        $prefix = $pathRaw === '' ? $n : $pathRaw . '/' . $n; ?>
        <form method="POST" id="create_nested_<?= h($n) ?>" hidden><input type="hidden" name="create_nested" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($prefix) ?>"></form>
        <form method="POST" action="<?= h($BASE) ?>" id="reorder_nested_<?= h($n) ?>" hidden><input type="hidden" name="reorder_nested" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($prefix) ?>"><input type="hidden" name="order" value=""></form>
        <?php foreach ($children as $k => $child): $childId = (int) ($child['id'] ?? 0);
            $near = $children[$k + 1] ?? $children[$k - 1] ?? null;
            $returnTo = $near !== null ? 'item-' . $n . '-' . (int) $near['id'] : 'list-' . $n; ?>
            <?= visibilityForm('vis_' . $n . '_' . $childId, $group, $id, $prefix . '/' . $childId) ?>
            <form method="POST" id="del_nested_<?= h($n) ?>_<?= $childId ?>" hidden data-confirm-title="Eliminare questo elemento?" data-confirm="«<?= h(editorTitle($child, $childFields)) ?>» e i suoi elementi verranno eliminati<?= $stagingMode ? ' quando pubblichi' : ' subito dal sito' ?>." data-confirm-label="Elimina"><input type="hidden" name="delete_nested" value="1"><input type="hidden" name="return_to" value="<?= h($returnTo) ?>"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($prefix . '/' . $childId) ?>"></form>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
$id = (int) $_GET['id'];
$pathRaw = $_GET['path'] ?? '';
$path = parsePath($pathRaw);
$rootIdx = findRootIndex($data, $group, $id);
$rootEntity = $rootIdx >= 0 ? $data[$rootIdx] : null;

$entity = null;
if ($rootEntity !== null) {
    if (empty($path)) {
        $entity = $rootEntity;
    } else {
        $rootCopy = $rootEntity;
        $target = &walkEntityPath($rootCopy, $path);
        if ($target !== null && isset($target['data'])) {
            $entity = ['id' => $target['id'] ?? 0, 'data' => $target['data']];
        }
        unset($target);
    }
}
$schema = resolveSchemaAtPath($models, $group, $path);
$getVal = fn($n) => $entity ? (array_column($entity['data'], 'value', 'name')[$n] ?? null) : null;

// Breadcrumb
$crumbs = [['label' => ucfirst($group), 'href' => "?action=list&group={$group}"]];
if (!empty($path)) {
    $crumbs[] = ['label' => "#{$id}", 'href' => "?action=edit&group={$group}&id={$id}"];
    for ($i = 0; $i < count($path); $i += 2) {
        $prefix = pathToString(array_slice($path, 0, $i + 2));
        $crumbs[] = [
            'label' => $path[$i] . ' #' . ($path[$i + 1] ?? '?'),
            'href' => "?action=edit&group={$group}&id={$id}&path=" . urlencode($prefix)
        ];
    }
}
$backHref = $crumbs[count($crumbs) - 2]['href'] ?? "?action=list&group={$group}";
$crumbLabel = end($crumbs)['label'];
?>
<div class="container">
    <h1>Modifica <?= htmlspecialchars($crumbLabel) ?>
        <?php if (!empty($path)): ?>
            <span class="badge" style="font-size:0.85rem; margin-left:10px;">#<?= (int) ($entity['id'] ?? 0) ?></span>
        <?php else: ?>
            <span class="badge" style="font-size:1rem; margin-left:10px;">#<?= $id ?></span>
        <?php endif; ?>
        <a href="<?= $backHref ?>" class="btn btn-white" onclick="return confirmExit(event)">Back</a>
    </h1>

    <?php if (count($crumbs) > 1): ?>
        <div style="margin-bottom:20px; font-size:0.9rem; color:#6b7280;">
            <?php foreach ($crumbs as $i => $c): ?>
                <?php if ($i > 0) echo '<span style="margin:0 6px;">/</span>'; ?>
                <a href="<?= $c['href'] ?>" style="color:#374151; text-decoration:none;"><?= htmlspecialchars($c['label']) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($entity === null): ?>
        <div class="card"><div class="card-body">Elemento non trovato.</div></div>
    <?php else: ?>
    <form method="POST" class="card">
        <div class="card-body">
            <input type="hidden" name="save_entity" value="1">
            <input type="hidden" name="group" value="<?= $group ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="path" value="<?= htmlspecialchars($pathRaw) ?>">

            <?php foreach ($schema as $f):
                $n = $f['name'];
                $t = $f['type'];
                $saved = $getVal($n); ?>
                <div style="margin-bottom:30px;">
                    <label><?= $n ?> <span style="font-weight:normal; color:#9ca3af; font-size:0.8em; margin-left:5px;"><?= strtoupper($t) ?></span></label>

                    <?php if ($t === 'array'):
                        $children = is_array($saved) ? $saved : [];
                        $childPathPrefix = $pathRaw === '' ? $n : ($pathRaw . '/' . $n);
                        ?>
                        <div style="background:#f9fafb; border:1px solid var(--border); border-radius:6px; padding:15px;">
                            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                                <span style="color:#6b7280; font-size:0.9rem;"><?= count($children) ?> elemento/i</span>
                                <button type="button" class="btn btn-white"
                                    onclick="document.getElementById('create_nested_<?= $n ?>').submit()">+ Nuovo</button>
                            </div>
                            <?php if (empty($children)): ?>
                                <p style="color:#9ca3af; margin:0; font-size:0.9rem;">Nessun elemento. Aggiungi il primo.</p>
                            <?php else: ?>
                                <table>
                                    <thead>
                                        <tr><th width="60">ID</th><th>Anteprima</th><th width="140" style="text-align:right">Azioni</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($children as $ch):
                                            $chId = (int) ($ch['id'] ?? 0);
                                            $prev = '<em style="color:#9ca3af">Vuoto</em>';
                                            foreach ($ch['data'] ?? [] as $d) {
                                                if (in_array($d['type'] ?? '', ['text','plain','richtext'])) {
                                                    $v = is_array($d['value']) ? reset($d['value']) : $d['value'];
                                                    if ($v) { $prev = mb_substr(strip_tags((string)$v), 0, 70) . '...'; break; }
                                                }
                                            }
                                            $childPath = $childPathPrefix . '/' . $chId;
                                            ?>
                                            <tr>
                                                <td><span class="badge">#<?= $chId ?></span></td>
                                                <td><?= $prev ?></td>
                                                <td style="text-align:right;">
                                                    <a href="?action=edit&group=<?= $group ?>&id=<?= $id ?>&path=<?= urlencode($childPath) ?>"
                                                        class="btn btn-white" style="padding:6px 10px;">Edit</a>
                                                    <button type="button" class="btn btn-white"
                                                        style="padding:6px 10px; color:var(--danger); border-color:var(--border);"
                                                        onclick="if(confirm('Eliminare questo elemento?')){document.getElementById('del_nested_<?= $n ?>_<?= $chId ?>').submit();}">✕</button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($t === 'plain'): ?>
                        <textarea name="<?= $n ?>[val]" rows="2"><?= is_array($saved) ? '' : $saved ?></textarea>

                    <?php else: ?>
                        <div class="tabs-container">
                            <div class="tabs-header">
                                <?php foreach ($activeLangs as $i => $l): ?>
                                    <div class="tab-btn <?= $i == 0 ? 'active' : '' ?>"
                                        onclick="openTab(this, '<?= $n ?>_<?= $l ?>', '<?= $n ?>')">
                                        <?= strtoupper($l) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php foreach ($activeLangs as $i => $l):
                                $v = is_array($saved) ? ($saved[$l] ?? '') : ''; ?>
                                <div class="tab-content group-<?= $n ?> <?= $i == 0 ? 'active' : '' ?>" id="<?= $n ?>_<?= $l ?>">
                                    <?php if ($t === 'image'): ?>
                                        <div style="display:flex; gap:10px; align-items:center;">
                                            <input type="text" name="<?= $n ?>[<?= $l ?>]" value="<?= $v ?>"
                                                id="in_<?= $n ?>_<?= $l ?>" placeholder="../assets/uploads/...">
                                            <button type="button" class="btn btn-white"
                                                onclick="pickMedia('in_<?= $n ?>_<?= $l ?>')">Scegli</button>
                                        </div>
                                        <?php if ($v):
                                            $previewSrc = (strpos($v, '../') === false && strpos($v, 'http') !== 0) ? '../' . $v : $v; ?>
                                            <div style="margin-top:10px; padding:5px; border:1px solid var(--border); border-radius:6px; display:inline-block; background:white;">
                                                <img src="<?= $previewSrc ?>" style="height:100px; display:block; object-fit:cover;">
                                            </div>
                                        <?php endif; ?>

                                    <?php elseif ($t === 'richtext'): ?>
                                        <textarea name="<?= $n ?>[<?= $l ?>]" class="richtext"><?= htmlspecialchars($v) ?></textarea>

                                    <?php else: ?>
                                        <textarea name="<?= $n ?>[<?= $l ?>]" rows="4" style="min-height:100px;"><?= $v ?></textarea>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="modal-footer" style="background:#f9fafb; border-top:1px solid var(--border);">
            <button class="btn btn-primary" style="padding:12px 30px; font-size:1rem;">Salva Modifiche</button>
        </div>
    </form>

    <?php // Helper forms for nested create/delete (outside the main edit form)
    foreach ($schema as $f):
        if (($f['type'] ?? '') !== 'array') continue;
        $n = $f['name'];
        $saved = $getVal($n);
        $children = is_array($saved) ? $saved : [];
        $childPathPrefix = $pathRaw === '' ? $n : ($pathRaw . '/' . $n);
        ?>
        <form method="POST" id="create_nested_<?= $n ?>" style="display:none;">
            <input type="hidden" name="create_nested" value="1">
            <input type="hidden" name="group" value="<?= $group ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="path" value="<?= htmlspecialchars($childPathPrefix) ?>">
        </form>
        <?php foreach ($children as $ch):
            $chId = (int) ($ch['id'] ?? 0);
            $childPath = $childPathPrefix . '/' . $chId;
            ?>
            <form method="POST" id="del_nested_<?= $n ?>_<?= $chId ?>" style="display:none;">
                <input type="hidden" name="delete_nested" value="1">
                <input type="hidden" name="group" value="<?= $group ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="path" value="<?= htmlspecialchars($childPath) ?>">
            </form>
        <?php endforeach; ?>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

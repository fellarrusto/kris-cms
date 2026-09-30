<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
// Accesso rapido agli elenchi marcati "posts": true. Le azioni sono le stesse
// della modifica annidata (create/delete/reorder_nested) sul percorso del campo.
$field = is_string($_GET['field'] ?? null) ? $_GET['field'] : '';
$source = null;
foreach ($postSources as $s) {
    if ($s['group'] === $group && $s['id'] === (int) ($_GET['id'] ?? -1) && $s['field'] === $field) $source = $s;
}
?>
<div class="container">
<?php if ($source === null): ?>
    <header class="page-heading"><div><p class="eyebrow">ACCESSO RAPIDO</p><h1>Posts</h1><p>Gli elenchi che aggiorni più spesso, senza cercarli dentro le pagine.</p></div></header>
    <?php if ($group !== null || $field !== ''): ?><div class="alert alert-error" role="alert">Questo elenco non è più disponibile. Scegline uno qui sotto.</div><?php endif; ?>
    <div class="collection-grid">
    <?php foreach ($postSources as $s): $href = '?action=posts&group=' . urlencode($s['group']) . '&id=' . $s['id'] . '&field=' . urlencode($s['field']); ?>
        <article class="collection-card"><div class="collection-card-top"><span class="collection-icon"><?= uiIcon('posts') ?></span><span class="badge"><?= count($s['items']) ?> post</span></div><h2><a href="<?= h($href) ?>"><?= h($s['label']) ?></a></h2><p><?= h(editorLabel($s['group'])) ?> &middot; <?= h($s['owner']) ?></p><div class="collection-card-bottom"><a class="text-link" href="<?= h($href) ?>">Apri elenco <?= uiIcon('arrow') ?></a></div></article>
    <?php endforeach; ?>
    </div>
    <?php if (!$postSources): ?><div class="empty-state"><h2>Nessun elenco in Posts.</h2><p>In Struttura, attiva “Mostra in Posts” su un elenco di primo livello.</p></div><?php endif; ?>
<?php else:
    $g = $source['group']; $id = $source['id']; $items = $source['items'];
    $editUrl = '?action=edit&group=' . urlencode($g) . '&id=' . $id;
?>
    <header class="page-heading"><div><p class="eyebrow">POSTS &middot; <?= h(editorLabel($g)) ?> / <?= h($source['owner']) ?></p><h1><?= h($source['label']) ?></h1><p>I nuovi post vengono aggiunti in cima. L’ordine qui è quello del sito.</p></div><div class="actions"><a class="btn btn-white" href="?action=posts"><?= uiIcon('back') ?>Tutti gli elenchi</a><button type="submit" form="create_post" class="btn btn-primary"><?= uiIcon('plus') ?>Nuovo post</button></div></header>
    <?php if (!$items): ?><div class="empty-state"><h2>Ancora nessun post.</h2><p>Usa “Nuovo post” per scrivere il primo.</p></div>
    <?php else: ?>
    <div class="toolbar"><label class="search-box"><?= uiIcon('search') ?><input type="search" data-filter="posts" placeholder="Cerca un post..." aria-label="Cerca un post"></label><span class="badge"><?= count($items) ?> post</span></div>
    <div class="card table-card"><table class="dnd-table content-table" data-dnd-scope="nested" data-filter-list="posts" data-reorder-form="reorder_posts"><thead><tr><th>Post</th><th class="order-column">Ordine</th><th><span class="sr-only">Azioni</span></th></tr></thead><tbody>
    <?php foreach ($items as $index => $item): $chId = (int) ($item['id'] ?? 0); $title = editorTitle($item); $href = $editUrl . '&path=' . urlencode($field . '/' . $chId); ?>
    <tr draggable="true" data-id="<?= $chId ?>" data-search="<?= h($title) ?>"><td><div class="content-label"><span class="collection-icon"><?= uiIcon('posts') ?></span><div><a class="content-title" href="<?= h($href) ?>"><?= h($title) ?></a><small>Post #<?= $chId ?></small></div></div></td><td><div class="order-actions"><button type="button" class="icon-button" data-move="-1" aria-label="Sposta <?= h($title) ?> in alto" <?= $index === 0 ? 'disabled' : '' ?>><?= uiIcon('up') ?></button><button type="button" class="icon-button" data-move="1" aria-label="Sposta <?= h($title) ?> in basso" <?= $index === count($items)-1 ? 'disabled' : '' ?>><?= uiIcon('down') ?></button></div></td><td><div class="row-actions"><a class="btn btn-white" href="<?= h($href) ?>">Modifica</a><form method="POST" data-confirm-title="Eliminare questo post?" data-confirm="&ldquo;<?= h($title) ?>&rdquo; verrà eliminato subito dal sito. Non puoi annullare dall’editor." data-confirm-label="Elimina post"><input type="hidden" name="delete_nested" value="1"><input type="hidden" name="return_posts" value="1"><input type="hidden" name="group" value="<?= h($g) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($field . '/' . $chId) ?>"><button class="icon-button danger" aria-label="Elimina <?= h($title) ?>"><?= uiIcon('trash') ?></button></form></div></td></tr>
    <?php endforeach; ?></tbody></table></div><div class="empty-state" data-filter-empty="posts" hidden><h2>Nessun post trovato.</h2><p>Prova un altro titolo o svuota la ricerca.</p></div><p class="hint">L’ordine viene salvato subito. Puoi usare le frecce oppure trascinare le righe.</p>
    <?php endif; ?>
    <form method="POST" id="create_post" hidden><input type="hidden" name="create_nested" value="1"><input type="hidden" name="group" value="<?= h($g) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($field) ?>"></form>
    <form method="POST" action="<?= h($BASE) ?>" id="reorder_posts" hidden><input type="hidden" name="reorder_nested" value="1"><input type="hidden" name="return_posts" value="1"><input type="hidden" name="group" value="<?= h($g) ?>"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="path" value="<?= h($field) ?>"><input type="hidden" name="order" value=""></form>
<?php endif; ?>
</div>

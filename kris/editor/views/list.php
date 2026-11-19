<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
$list = editorItems($group);
$fields = editorFields($group);
$hiddenCount = count(array_filter($list, 'isHiddenItem'));
// Con un solo contenuto sospenderlo spegnerebbe la pagina
$canHide = count($list) > 1;
$canCreate = !(isSingleCollection($group) && $list);
$returnTo = '?action=list&group=' . urlencode($group);
?>
<div class="container">
    <div class="page-head">
        <div>
            <h1><?= h(collectionLabel($group)) ?></h1>
            <p class="sub"><?= collectionDescription($group) !== '' ? h(collectionDescription($group)) . ' ' : '' ?><span class="num"><?= count($list) ?></span> <?= count($list) === 1 ? 'contenuto' : 'contenuti' ?><?= $hiddenCount ? ', ' . $hiddenCount . ($hiddenCount === 1 ? ' sospeso' : ' sospesi') : '' ?>.<?= isNewestFirst($group) ? ' I nuovi entrano in cima.' : '' ?></p>
        </div>
        <div class="actions">
            <?php if (!$stagingMode): ?><a class="btn btn-white" href="?action=structure&amp;group=<?= urlencode($group) ?>"><?= uiIcon('code') ?>Struttura</a><?php endif; ?>
            <?php if ($fields && $canCreate): ?><form method="POST"><input type="hidden" name="group" value="<?= h($group) ?>"><button class="btn btn-primary" name="create_instance" value="1"><?= uiIcon('plus') ?>Nuovo contenuto</button></form><?php endif; ?>
        </div>
    </div>
    <?php if (!$fields): ?>
        <div class="empty-state"><h2>Prima definiamo cosa raccontare.</h2><p>Questa raccolta non ha ancora campi: scegli in Struttura cosa contiene ogni contenuto.</p><?php if (!$stagingMode): ?><a class="btn btn-primary" href="?action=structure&amp;group=<?= urlencode($group) ?>">Scegli i campi</a><?php endif; ?></div>
    <?php elseif (!$list): ?>
        <div class="empty-state"><h2>Il primo contenuto, quando vuoi.</h2><p>Usa «Nuovo contenuto» per iniziare a compilare la raccolta.</p></div>
    <?php else: ?>
        <div class="toolbar"><label class="search"><?= uiIcon('search') ?><input type="search" data-filter="contents" placeholder="Cerca in <?= h(mb_strtolower(collectionLabel($group))) ?>" aria-label="Cerca un contenuto"></label><span class="order-note" data-order-note>Trascina dalla maniglia, o usa le frecce sulla maniglia, per cambiare l'ordine sul sito. Si salva da solo.</span></div>
        <div class="rows" data-dnd-list data-reorder-form="reorder_root_form" data-filter-list="contents">
        <?php foreach ($list as $item):
            $itemId = (int) $item['id'];
            $title = editorTitle($item, $fields);
            $sub = editorSubtitle($item, $fields, $title);
            $thumb = editorThumb($item, $fields);
            $hidden = isHiddenItem($item);
            $href = '?action=edit&group=' . urlencode($group) . '&id=' . $itemId; ?>
            <div class="row<?= $hidden ? ' is-hidden' : '' ?>" data-id="<?= $itemId ?>" data-search="<?= h($title . ' ' . $sub) ?>">
                <a class="row-main" href="<?= h($href) ?>"><span class="thumb"><?= $thumb !== '' ? editorMediaThumb(editorMediaSource($thumb), $thumb) : uiIcon(isNewestFirst($group) ? 'posts' : 'page') ?></span><span class="row-text"><span class="row-title"><?= h($title) ?></span><?php if ($sub !== ''): ?><span class="row-sub"><?= h($sub) ?></span><?php endif; ?></span></a>
                <div class="row-actions">
                    <?php if ($hidden): ?><span class="pill">Sospeso</span><?php endif; ?>
                    <a class="btn btn-white btn-small" href="<?= h($href) ?>">Modifica</a>
                    <?php if ($canHide): ?><?= visibilityToggle('vis_root_' . $itemId, !$hidden, $title) ?><?php endif; ?>
                    <?php if (!isSingleCollection($group)): ?><button type="submit" form="del_root_<?= $itemId ?>" class="icon-button danger" aria-label="Elimina <?= h($title) ?>"><?= uiIcon('trash') ?></button><?php endif; ?>
                    <button type="button" class="icon-button handle" data-handle aria-label="Sposta <?= h($title) ?>: trascina, o usa le frecce su e giù"><?= uiIcon('drag') ?></button>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <div class="empty-state" data-filter-empty="contents" hidden><h2>Nessun contenuto trovato.</h2><p>Prova un altro nome o svuota la ricerca.</p></div>
        <?php foreach ($list as $item): $itemId = (int) $item['id']; ?>
            <?php if ($canHide): ?><?= visibilityForm('vis_root_' . $itemId, $group, $itemId, '') ?><?php endif; ?>
            <form method="POST" id="del_root_<?= $itemId ?>" hidden data-confirm-title="Eliminare questo contenuto?" data-confirm="«<?= h(editorTitle($item, $fields)) ?>» e i suoi elementi verranno eliminati<?= $stagingMode ? ' quando pubblichi' : ' subito dal sito' ?>." data-confirm-label="Elimina"><input type="hidden" name="delete_instance" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="id" value="<?= $itemId ?>"></form>
        <?php endforeach; ?>
        <form method="POST" action="<?= h($BASE) ?>" id="reorder_root_form" hidden><input type="hidden" name="reorder_root" value="1"><input type="hidden" name="group" value="<?= h($group) ?>"><input type="hidden" name="order" value=""></form>
    <?php endif; ?>
</div>

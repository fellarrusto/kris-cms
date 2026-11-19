<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
// Contenuti: le pagine e i blocchi (contenuti unici) aprono la modifica, le raccolte la loro lista
$singles = array_filter($userTypes, fn($t, $name) => isSingleCollection((string) $name), ARRAY_FILTER_USE_BOTH);
$collections = array_diff_key($userTypes, $singles);
// Una pagina dello schema rende il tipo: è una pagina; altrimenti un blocco condiviso (menu, piè di pagina)
$paged = array_flip(array_map(fn($p) => (string) ($p['type'] ?? ''), array_filter(editorSite()['schema']['pages'], 'is_array')));
$tile = function (string $name) use ($counts, $paged): string {
    $single = isSingleCollection($name);
    $count = (int) ($counts[$name] ?? 0);
    $hidden = count(array_filter(editorItems($name), 'isHiddenItem'));
    $description = collectionDescription($name);
    $fields = count(editorFields($name));
    $foot = $single
        ? '<span>' . ($fields === 1 ? '1 campo' : $fields . ' campi') . '</span><span class="tag">' . (isset($paged[$name]) ? 'Pagina' : 'Blocco') . '</span>'
        : '<span><span class="num">' . $count . '</span> ' . ($count === 1 ? 'contenuto' : 'contenuti') . ($hidden ? ' · ' . $hidden . ($hidden === 1 ? ' sospeso' : ' sospesi') : '') . '</span><span class="tag">' . (isNewestFirst($name) ? 'Articoli' : 'Raccolta') . '</span>';
    return '<a class="tile" href="?action=list&amp;group=' . urlencode($name) . '" data-search="' . h(collectionLabel($name) . ' ' . $name) . '">'
        . '<div class="tile-head"><span class="ic">' . uiIcon($single ? 'page' : (isNewestFirst($name) ? 'posts' : 'list')) . '</span><span class="tile-name">' . h(collectionLabel($name)) . '</span></div>'
        . ($description !== '' ? '<p class="tile-text">' . h($description) . '</p>' : '')
        . '<div class="tile-foot">' . $foot . '</div></a>';
};
?>
<div class="container">
    <div class="page-head">
        <div><h1>Cosa vuoi modificare?</h1><p class="sub"><?= $stagingMode ? 'Le modifiche restano in attesa finché non le pubblichi.' : 'I contenuti salvati sono subito visibili sul sito.' ?></p></div>
        <?php if ($userTypes): ?><label class="search"><?= uiIcon('search') ?><input type="search" data-filter="types" placeholder="Cerca un contenuto" aria-label="Cerca un contenuto"></label><?php endif; ?>
    </div>
    <div data-filter-list="types" class="container">
    <?php if ($singles): ?>
        <div class="section-head"><h2>Pagine e blocchi</h2><span>Contenuti unici: si aprono direttamente in modifica</span></div>
        <div class="grid"><?php foreach (array_keys($singles) as $name) echo $tile((string) $name); ?></div>
    <?php endif; ?>
    <?php if ($collections): ?>
        <div class="section-head"><h2>Raccolte</h2><span>Elenchi di contenuti che puoi ordinare</span></div>
        <div class="grid"><?php foreach (array_keys($collections) as $name) echo $tile((string) $name); ?></div>
    <?php endif; ?>
    </div>
    <div class="empty-state" data-filter-empty="types" <?= $userTypes ? 'hidden' : '' ?>>
        <h2><?= $userTypes ? 'Nessun contenuto con questo nome.' : 'Il primo contenuto inizia da qui.' ?></h2>
        <p><?= $userTypes ? 'Prova un altro nome o svuota la ricerca.' : 'Crea una raccolta in Struttura e scegli i campi che vuoi gestire.' ?></p>
        <?php if (!$userTypes && !$stagingMode): ?><a class="btn btn-primary" href="?action=structure"><?= uiIcon('plus') ?>Nuova raccolta</a><?php endif; ?>
    </div>
</div>

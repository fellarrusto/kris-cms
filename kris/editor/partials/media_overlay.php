<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<dialog id="mediaOverlay" class="dialog media-dialog" aria-labelledby="mediaTitle">
    <div class="dialog-head"><h2 id="mediaTitle">Scegli un file</h2><button type="button" class="icon-button" data-close-dialog aria-label="Chiudi"><?= uiIcon('close') ?></button></div>
    <div class="dialog-body">
        <div class="toolbar"><label class="search" style="flex:1"><?= uiIcon('search') ?><input type="search" data-filter="picker" placeholder="Cerca per nome" aria-label="Cerca nella libreria"></label><button type="button" class="btn btn-white" data-upload-trigger="overlayUpload"><?= uiIcon('upload') ?>Carica un file</button><input type="file" id="overlayUpload" data-upload-input accept="<?= h(implode(',', array_map(fn($ext) => '.' . $ext, \Kris\Entity\MediaStore::allowedExtensions()))) ?>" hidden></div>
        <div class="upload-results" data-upload-results aria-live="polite"></div>
        <div id="mediaGrid" class="media-grid" data-filter-list="picker">
        <?php foreach ($images as $img): $name = basename($img); $url = $uploadUrl . rawurlencode($name); ?>
            <button type="button" class="media-choice" data-media-url="<?= h($url) ?>" data-search="<?= h($name) ?>" aria-pressed="false"><span class="media-art"><?= editorMediaThumb(editorMediaSource($url), $name) ?></span><span class="media-caption"><?= h($name) ?></span></button>
        <?php endforeach; ?>
        </div>
        <div class="empty-state" data-filter-empty="picker" <?= $images ? 'hidden' : '' ?>><h3>Nessun file trovato.</h3><p>Carica un file o cambia la ricerca.</p></div>
    </div>
    <div class="dialog-foot"><span id="mediaSelectionLabel" class="selection-label">Nessun file selezionato</span><button type="button" class="btn btn-white" data-close-dialog>Annulla</button><button type="button" class="btn btn-primary" id="applyMedia" disabled>Usa questo file</button></div>
</dialog>

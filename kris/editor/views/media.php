<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; }
$accept = implode(',', array_map(fn($ext) => '.' . $ext, \Kris\Entity\MediaStore::allowedExtensions())); ?>
<div class="container">
    <div class="page-head">
        <div><h1>Libreria media</h1><p class="sub"><span class="num" data-media-count><?= count($images) ?></span> file<?= $stagingMode ? '. I file caricati ora restano in attesa finché non pubblichi.' : '' ?></p></div>
        <?php if (!$stagingMode && \Kris\Entity\MediaStore::canWriteWebp()): ?><form method="POST" class="actions"><input type="hidden" name="optimize_media" value="1"><button class="btn btn-white" title="Crea le misure per schermi diversi delle immagini già caricate">Ottimizza le immagini</button></form><?php endif; ?>
    </div>
    <form class="dropzone" method="POST" enctype="multipart/form-data" action="./upload.php" data-upload-form data-dropzone>
        <div><strong>Trascina qui i file, oppure</strong><p class="muted">Immagini e PDF fino a <?= (int) (\Kris\Entity\MediaStore::maxBytes() / 1024 / 1024) ?> MB, video MP4 e WebM fino a <?= (int) (\Kris\Entity\MediaStore::videoMaxBytes() / 1024 / 1024) ?> MB. Le foto vengono ridotte e ripulite dai dati nascosti.</p></div>
        <input id="libraryUpload" type="file" name="file" accept="<?= h($accept) ?>" data-upload-input multiple hidden>
        <button type="button" class="btn btn-primary" data-upload-trigger="libraryUpload"><?= uiIcon('upload') ?>Scegli file</button>
    </form>
    <div class="upload-results" data-upload-results aria-live="polite"></div>
    <div class="toolbar"><label class="search"><?= uiIcon('search') ?><input type="search" data-filter="library" placeholder="Cerca per nome" aria-label="Cerca un file"></label></div>
    <div class="media-grid" id="libraryGrid" data-filter-list="library">
    <?php foreach ($images as $img):
        $name = basename($img);
        $url = $uploadUrl . rawurlencode($name);
        $staged = isset(editorStagedUploads()[$name]); ?>
        <article class="media-card" data-search="<?= h($name) ?>">
            <a class="media-art" href="<?= h(editorMediaSource($url)) ?>" target="_blank" rel="noopener" aria-label="Apri <?= h($name) ?>"><?= editorMediaThumb(editorMediaSource($url), $name, $name) ?><?php if ($staged): ?><span class="pill pill-pending media-badge">In attesa</span><?php endif; ?></a>
            <div class="media-name" title="<?= h($name) ?>"><?= h($name) ?></div>
            <div class="media-meta"><span><?= h(strtoupper(pathinfo($name, PATHINFO_EXTENSION))) ?> · <?= h(editorFileSize((int) @filesize($img))) ?></span><span>
                <button class="icon-button" type="button" data-copy-url="<?= h($url) ?>" aria-label="Copia l'indirizzo di <?= h($name) ?>"><?= uiIcon('copy') ?></button>
                <?php if (!$stagingMode && !$staged): ?><form method="POST" data-confirm-title="Eliminare questo file?" data-confirm="«<?= h($name) ?>» verrà eliminato definitivamente, con le sue misure. Se un contenuto lo usa, lì comparirà un vuoto." data-confirm-label="Elimina file"><input type="hidden" name="delete_media" value="1"><input type="hidden" name="file_name" value="<?= h($name) ?>"><button class="icon-button danger" aria-label="Elimina <?= h($name) ?>"><?= uiIcon('trash') ?></button></form><?php endif; ?>
            </span></div>
        </article>
    <?php endforeach; ?>
    </div>
    <div class="empty-state" data-filter-empty="library" <?= $images ? 'hidden' : '' ?>><h2>Nessun file da mostrare.</h2><p>Carica il primo file, oppure cambia la ricerca.</p></div>
</div>

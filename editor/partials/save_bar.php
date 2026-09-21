<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<div class="savebar" data-savebar>
    <div class="save-status"><span class="save-indicator"><?= uiIcon('check') ?></span><div><strong data-save-status><?= $msg ? h($msg) : 'Tutto pronto per lavorare' ?></strong><small data-save-hint><?= $action === 'edit' ? 'I contenuti salvati sono subito visibili sul sito.' : 'Salva per applicare le modifiche.' ?></small></div></div>
    <div class="save-actions"><button type="button" class="btn btn-quiet" data-discard hidden>Annulla modifiche</button><button type="submit" class="btn btn-primary" data-save-button><?= uiIcon('check') ?><?= $action === 'settings' ? 'Salva impostazioni' : ($action === 'structure' ? 'Controlla e salva' : 'Salva modifiche') ?></button></div>
</div>

<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<div class="savebar" data-savebar role="status">
    <div class="savebar-text"><strong data-save-status>Tutte le modifiche sono salvate</strong><small><?= $action === 'edit' ? ($stagingMode ? 'Salvando, diventano modifiche in attesa. Il sito non cambia.' : 'Salvando, le modifiche sono subito visibili sul sito.') : 'Salva per applicare le modifiche.' ?></small></div>
    <button type="button" class="btn" data-discard hidden>Annulla modifiche</button>
    <button type="submit" class="btn" data-save-button><?= $action === 'settings' ? 'Salva impostazioni' : ($action === 'structure' ? 'Salva struttura' : ($stagingMode ? 'Salva in attesa' : 'Salva')) ?></button>
</div>

<?php
declare(strict_types=1);

if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * Funzioni del pannello che non dipendono dai dati: testo, date, file della
 * libreria, percorsi, icone e controlli ricorrenti. Il formato dei dati è in
 * model.php.
 */

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Data ISO 8601 in forma leggibile ("30/09/2026 alle 14:05"), o null se assente o illeggibile.
 * Sempre in ora italiana: molti hosting tengono PHP in UTC.
 */
function editorDateTime(mixed $iso): ?string
{
    if (!is_string($iso) || $iso === '') return null;
    try {
        $date = (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('Europe/Rome'));
    } catch (Exception) {
        return null;
    }
    return $date->format('d/m/Y') . ' alle ' . $date->format('H:i');
}

/**
 * Vero se la richiesta cambia struttura o numero dei contenuti di un tipo
 * riservato (kris_, plg_), o crea un tipo con un prefisso riservato.
 */
function isReservedStructuralPost(array $post): bool
{
    $structural = ['create_collection', 'save_structure', 'delete_collection', 'save_collection_info', 'create_instance', 'delete_instance', 'reorder_root', 'toggle_post_collection'];
    if (!array_intersect($structural, array_keys($post))) return false;
    $newName = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($post['collection_name'] ?? '')));
    return isReservedCollection($post['group_name'] ?? null) || isReservedCollection($post['group'] ?? null) || isReservedCollection($newName);
}

/**
 * Richieste che non riguardano i contenuti (account, Kris Agent, pubblicazione,
 * impostazioni del sito): valgono uguali anche in «In attesa».
 */
function isStagingNeutralPost(array $post): bool
{
    $neutral = ['save_account_email', 'account_email_later', 'send_test_email', 'change_password',
        'agent_pair', 'agent_toggle', 'agent_unpair', 'agent_grant', 'agent_reset',
        'staging_promote', 'staging_discard', 'staging_undo', 'approve_code', 'save_upload_settings'];
    return (bool) array_intersect($neutral, array_keys($post));
}

/**
 * I file caricati fra le modifiche in attesa e non ancora pubblici: l'editor li
 * mostra attraverso l'anteprima. Si impostano una volta, poi si leggono.
 *
 * @param string[]|null $names
 * @return array<string, true>
 */
function editorStagedUploads(?array $names = null): array
{
    static $set = [];
    if ($names !== null) $set = array_fill_keys($names, true);
    return $set;
}

/** Che cosa è un file della libreria, dall'estensione: image, video o pdf. */
function editorMediaKind(string $name): string
{
    $ext = strtolower(pathinfo((string) parse_url($name, PHP_URL_PATH), PATHINFO_EXTENSION));
    return $ext === 'pdf' ? 'pdf' : (in_array($ext, \Kris\Entity\MediaStore::VIDEO_EXTENSIONS, true) ? 'video' : 'image');
}

/** Anteprima di un file della libreria: immagine, video senza audio o segnaposto del PDF. */
function editorMediaThumb(string $src, string $name, string $alt = ''): string
{
    return match (editorMediaKind($name)) {
        'pdf' => '<span class="file-placeholder">PDF</span>',
        'video' => '<video src="' . h($src) . '" muted playsinline preload="metadata" aria-label="' . h($alt !== '' ? $alt : $name) . '"></video>',
        default => '<img src="' . h($src) . '" alt="' . h($alt) . '" loading="lazy">',
    };
}

function editorMediaFiles(string $directory): array
{
    $extensions = [...\Kris\Entity\MediaStore::allowedExtensions(), 'svg'];
    $files = array_values(array_filter(glob($directory . '*') ?: [],
        fn($file) => is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)
            && !\Kris\Entity\MediaStore::isVariant(basename($file))));
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

/** Peso leggibile: «412 KB», «1,2 MB». */
function editorFileSize(int $bytes): string
{
    return $bytes >= 1048576 ? number_format($bytes / 1048576, 1, ',', '.') . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
}

function editorMediaSource(string $url): string
{
    if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return preg_match('~^https?://~i', $url) ? $url : '';
    }
    if (preg_match('~^assets/uploads/([A-Za-z0-9._-]+)$~', $url, $m) && isset(editorStagedUploads()[$m[1]])) {
        return '../' . \Kris\Staging\Preview::assetUrl('uploads/' . rawurlencode($m[1]));
    }
    return str_starts_with($url, '/') || str_starts_with($url, '../') ? $url : '../' . $url;
}

/** Icone dell'editor: tratto da 1,6 su una griglia di 24. */
function uiIcon(string $name): string
{
    $paths = [
        'grid' => '<rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/>',
        'media' => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M20 16l-5-5-8 8"/>',
        'list' => '<path d="M5 7h14M5 12h14M5 17h9"/>',
        'page' => '<path d="M6 3h9l4 4v14H6z"/>',
        'block' => '<path d="M4 17h16"/><rect x="4" y="5" width="16" height="8" rx="2"/>',
        'posts' => '<path d="M5 5h14v14H5zM8 9h8M8 13h8M8 16h5"/>',
        'globe' => '<circle cx="12" cy="12" r="8"/><path d="M4 12h16M12 4c2.5 3 2.5 13 0 16M12 4c-2.5 3-2.5 13 0 16"/>',
        'shield' => '<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"/>',
        'notfound' => '<circle cx="12" cy="12" r="8"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'wip' => '<path d="M4 20h16M7 20V9l5-4 5 4v11"/>',
        'code' => '<path d="M8 6l-5 6 5 6M16 6l5 6-5 6"/>',
        'link' => '<path d="M10 14a4 4 0 006 0l3-3a4 4 0 00-6-6l-1 1M14 10a4 4 0 00-6 0l-3 3a4 4 0 006 6l1-1"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
        'logout' => '<path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/>',
        'publish' => '<path d="M12 4v11M7 9l5-5 5 5M5 20h14"/>',
        'clock' => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>',
        'external' => '<path d="M14 5h5v5M19 5l-8 8M17 14v5H5V7h5"/>',
        'search' => '<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'back' => '<path d="M15 6l-6 6 6 6"/>',
        'arrow' => '<path d="M9 6l6 6-6 6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'trash' => '<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
        'up' => '<path d="m6 15 6-6 6 6"/>',
        'down' => '<path d="m6 9 6 6 6-6"/>',
        'drag' => '<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>',
        'upload' => '<path d="M12 16V4M7 9l5-5 5 5M4 15v5h16v-5"/>',
        'copy' => '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 00-1-1H5a1 1 0 00-1 1v10a1 1 0 001 1h3"/>',
        'theme' => '<path d="M12 3a9 9 0 100 18 7 7 0 010-18z"/>',
        'warning' => '<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/>',
        'agent' => '<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M18 15l.8 2.2L21 18l-2.2.8L18 21l-.8-2.2L15 18l2.2-.8z"/>',
        'language' => '<path d="M4 6h10M9 4v2c0 4-2 8-5 10M6 10c1 2 3 4 6 5"/><path d="M13 20l4-9 4 9M14.5 17h5"/>',
        'version' => '<path d="M4 12a8 8 0 0114-5.3M20 12a8 8 0 01-14 5.3"/><path d="M18 3v4h-4M6 21v-4h4"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['page']) . '</svg>';
}

/** Pagina di errore bloccante: mostrata quando i dati non sono leggibili. */
function renderStorageError(string $detail): void // termina sempre con exit
{
    http_response_code(500);
    $detail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="it"><head><meta charset="UTF-8">
    <title>Archivio non disponibile - Kris</title>
    <style>
        body { font-family: Geist, system-ui, sans-serif; background:#EDEDEA; color:#17171A;
               display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:16px; box-sizing:border-box; }
        .box { background:#fff; padding:32px; border-radius:20px; max-width:560px; }
        h1 { margin:0 0 12px; font-size:22px; font-weight:500; }
        p { color:#3A3936; line-height:1.55; }
        code { background:#F1F0EC; padding:8px 10px; border-radius:10px; font-size:13px;
               display:block; margin-top:14px; color:#7F1D1D; word-break:break-all; }
    </style></head><body><div class="box">
        <h1>I contenuti non sono disponibili</h1>
        <p><strong>Non è stato modificato nulla.</strong> L'archivio dei contenuti non è
        leggibile, quindi il pannello si è fermato per non sovrascriverlo.</p>
        <p>Le copie di sicurezza si trovano in <code>data/backups/</code>: ripristina la più
        recente rinominandola al posto del file danneggiato, poi ricarica questa pagina.</p>
        <code>{$detail}</code>
    </div></body></html>
    HTML;
    exit;
}

// --- Visibilità ---------------------------------------------------------------
// Un elemento sospeso ha "hidden": true: resta nei dati e nell'editor, ma il
// sito non lo mostra. Il form è separato (attributo form sull'interruttore)
// perché nelle liste annidate i controlli stanno già dentro un altro form.

/** Form nascosto che salva la visibilità di un elemento. */
function visibilityForm(string $formId, string $group, int $id, string $path): string
{
    return '<form method="POST" id="' . h($formId) . '" hidden data-visibility-form>'
        . '<input type="hidden" name="toggle_visibility" value="1">'
        . '<input type="hidden" name="group" value="' . h($group) . '">'
        . '<input type="hidden" name="id" value="' . $id . '">'
        . '<input type="hidden" name="path" value="' . h($path) . '"></form>';
}

/** Interruttore «Visibile» collegato a un form di visibilityForm(). */
function visibilityToggle(string $formId, bool $visible, string $title, bool $withLabel = false): string
{
    return '<label class="switch' . ($withLabel ? ' switch-labeled' : '') . '" title="Mostra o sospendi sul sito">'
        . ($withLabel ? '<span class="switch-text" data-visibility-label>' . ($visible ? 'Visibile' : 'Sospeso') . '</span>' : '')
        . '<input type="checkbox" role="switch" name="visible" value="1" form="' . h($formId) . '" data-visibility-toggle'
        . ($visible ? ' checked' : '') . ' aria-label="' . h('Visibile sul sito: ' . $title) . '">'
        . '<span class="switch-track" aria-hidden="true"></span></label>';
}

/**
 * Controllo di un interruttore o di una scelta nella modifica di un contenuto.
 * $name è il nome nel modulo, $value il valore salvato (true/false, una
 * stringa o una lista di opzioni).
 */
function editorChoiceInput(array $def, string $name, string $id, string $label, mixed $value): string
{
    if ($def['type'] === 'boolean') {
        // Il campo nascosto manda "" quando l'interruttore è spento
        return '<input type="hidden" name="' . h($name) . '" value=""><label class="switch switch-labeled" for="' . h($id) . '">'
            . '<input type="checkbox" role="switch" id="' . h($id) . '" name="' . h($name) . '" value="1"' . ($value === true || $value === '1' ? ' checked' : '') . '>'
            . '<span class="switch-track" aria-hidden="true"></span><span class="switch-text">Acceso</span></label>';
    }
    $picked = array_map('strval', is_array($value) ? $value : ($value === null || $value === '' ? [] : [$value]));
    if (!empty($def['multiple'])) {
        $out = '<fieldset class="chips"><legend class="sr-only">' . h($label) . '</legend><input type="hidden" name="' . h($name) . '[]" value="">';
        foreach ((array) ($def['options'] ?? []) as $i => $option) {
            $optId = $id . '-' . $i;
            $out .= '<label class="chip" for="' . h($optId) . '"><input type="checkbox" id="' . h($optId) . '" name="' . h($name) . '[]" value="' . h((string) $option) . '"'
                . (in_array((string) $option, $picked, true) ? ' checked' : '') . '><span>' . h((string) $option) . '</span></label>';
        }
        return $out . '</fieldset>';
    }
    $out = '<select class="input" id="' . h($id) . '" name="' . h($name) . '"><option value="">—</option>';
    foreach ((array) ($def['options'] ?? []) as $option) {
        $out .= '<option value="' . h((string) $option) . '"' . (in_array((string) $option, $picked, true) ? ' selected' : '') . '>' . h((string) $option) . '</option>';
    }
    return $out . '</select>';
}

// --- Percorsi annidati ---------------------------------------------------------
// "elenco/id/elenco/id..." (può finire su un nome di elenco)

function parsePath(?string $raw): array
{
    if ($raw === null || $raw === '') return [];
    return array_values(array_filter(explode('/', $raw), fn($s) => $s !== ''));
}

function pathToString(array $path): string
{
    return implode('/', $path);
}

/**
 * L'ancora dove tornare dopo un'azione su un elenco (return_to del modulo),
 * così la pagina resta all'altezza a cui si lavorava. Vuota se non valida.
 */
function returnAnchor(): string
{
    $anchor = (string) ($_POST['return_to'] ?? '');
    return preg_match('/^[A-Za-z0-9_-]{1,160}$/', $anchor) === 1 ? '#' . $anchor : '';
}

// --- Struttura ---------------------------------------------------------------

/**
 * Campi di primo livello della Struttura divisi in sezioni: un gruppo senza
 * sezione in testa, poi un riquadro per sezione con il nome modificabile una
 * volta sola. Al salvataggio ogni campo riceve il nome del suo riquadro.
 */
function renderSchemaRoot(array $schema): void
{
    $sections = schemaSections($schema);
    if ($sections === [] || $sections[0]['section'] !== '') array_unshift($sections, ['section' => '', 'fields' => []]);
    $keys = [];
    foreach ($sections as $i => $section) $keys[$i] = $section['section'] === '' ? 'none' : 's' . $i;
    $options = [];
    foreach ($sections as $i => $section) $options[$keys[$i]] = $section['section'];
    foreach ($sections as $i => $section):
        $key = $keys[$i];
        if ($section['section'] === ''): ?>
        <div class="sf-group sf-group-none" data-sf-group="<?= $key ?>"><p class="sf-group-label">Campi senza sezione</p><div class="sf-group-fields" data-sf-drop><?php renderSchemaFields($section['fields'], 0, $options, $key); ?></div></div>
        <?php else: ?>
        <section class="sf-group sf-section-group" data-sf-group="<?= $key ?>"><div class="sf-group-header"><label class="field-label">Nome della sezione<input type="text" class="input sf-section-name" value="<?= h($section['section']) ?>" maxlength="60" required placeholder="Es. Meta, Contatti"></label><button type="button" class="btn btn-white btn-small sf-section-remove">Togli sezione</button></div><div class="sf-group-fields" data-sf-drop><?php renderSchemaFields($section['fields'], 0, $options, $key); ?></div><p class="sf-drop-hint">Trascina qui i campi, oppure scegli questa sezione dal menu «Sezione» di un campo. Una sezione vuota non viene salvata.</p></section>
        <?php endif;
    endforeach;
}

/** Campi della Struttura, ricorsivo negli elenchi. Al primo livello maniglia e menu della sezione. */
function renderSchemaFields(array $schema, int $depth = 0, array $sections = [], string $current = 'none'): void
{
    foreach ($schema as $f):
        $type = $f['type'] ?? 'text';
        $isList = $type === 'list'; ?>
        <div class="sf-row" data-depth="<?= $depth ?>">
            <div class="sf-header">
                <?php if ($depth === 0): ?><button type="button" class="icon-button sf-drag" aria-label="Trascina per spostare il campo in un'altra sezione" title="Trascina"><?= uiIcon('drag') ?></button><?php endif; ?>
                <input type="text" class="input sf-name" value="<?= h($f['name'] ?? '') ?>" placeholder="nome_tecnico" aria-label="Nome tecnico del campo" required pattern="[a-z][a-z0-9_]*">
                <select class="input sf-type" aria-label="Tipo del campo">
                    <?php foreach (EDITOR_FIELD_TYPES as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $type === $val ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="icon-button danger sf-remove" aria-label="Rimuovi campo"><?= uiIcon('close') ?></button>
            </div>
            <label class="field-label sf-description-label">Descrizione per chi modifica<input type="text" class="input sf-description" value="<?= h($f['description'] ?? '') ?>" placeholder="Es. Titolo in testa alla pagina"></label>
            <?php if ($depth === 0): ?><label class="field-label sf-section-label">Sezione<select class="input sf-move"><?php foreach ($sections as $key => $name): ?><option value="<?= h($key) ?>" <?= $key === $current ? 'selected' : '' ?>><?= $name === '' ? 'Nessuna sezione' : h($name) ?></option><?php endforeach; ?></select></label><?php endif; ?>
            <div class="sf-scalar" <?= $isList ? 'hidden' : '' ?>>
                <label class="check-row"><input type="checkbox" class="sf-localized" <?= !empty($f['localized']) ? 'checked' : '' ?>><span><strong>Diverso per lingua</strong><small>Spento: un solo valore per tutte le lingue (prezzi, codici, indirizzi).</small></span></label>
                <div class="sf-options" <?= $type === 'select' ? '' : 'hidden' ?>>
                    <label class="field-label">Opzioni, una per riga<textarea class="input sf-options-list" rows="4"><?= h(implode("\n", (array) ($f['options'] ?? []))) ?></textarea></label>
                    <label class="check-row"><input type="checkbox" class="sf-multiple" <?= !empty($f['multiple']) ? 'checked' : '' ?>><span><strong>Più opzioni insieme</strong><small>Per tag e categorie: nei template si controllano con «has».</small></span></label>
                </div>
            </div>
            <div class="sf-nested" <?= $isList ? '' : 'hidden' ?>>
                <p class="sf-nested-label">Campi di ogni elemento</p>
                <?php if ($isList): renderSchemaFields($f['of'] ?? [], $depth + 1); endif; ?>
                <button type="button" class="btn btn-white btn-small sf-add-child"><?= uiIcon('plus') ?>Campo dell'elemento</button>
            </div>
        </div>
    <?php endforeach;
}

<?php
declare(strict_types=1);

use Kris\Staging\EditorChanges;
use Kris\Staging\Staging;
use Kris\Staging\StagingException;
if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * Azioni POST dei contenuti mentre si preparano modifiche in attesa.
 *
 * L'editor mostra i dati dello staging; qui ogni azione diventa operazioni
 * dello staging (vedi Kris\Staging\EditorChanges) invece di scrivere i file
 * del sito. Il sito pubblico non cambia finche non si pubblica da «Da
 * pubblicare». Incluso da actions.php, con le stesse variabili.
 */

$staging = new Staging();
$by = 'editor: ' . (string) ($_SESSION['kris_user'] ?? '');
$g = is_string($_POST['group'] ?? null) ? $_POST['group'] : '';
$isAjax = in_array($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '', ['save', 'reorder', 'visibility'], true);

try {
    if ($g !== '' && isReservedCollection($g)) {
        throw new StagingException('Privacy e cookie si modificano sul sito pubblico: passa a «Sito pubblico» per cambiarli.');
    }

    if (isset($_POST['save_entity'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        $rootIdx = findRootIndex($data, $g, $id);
        $copy = $rootIdx >= 0 ? $data[$rootIdx] : null;
        $target = $copy !== null && count($path) % 2 === 0 ? walkEntityPath($copy, $path) : null;
        if ($target === null) {
            throw new StagingException('Questo contenuto non esiste fra le modifiche in attesa: ricarica la pagina.');
        }
        $schema = resolveSchemaAtPath($models, $g, $path);
        $before = $target['data'] ?? [];
        $after = applyPostToData($before, $schema, $_POST, $activeLangs);
        $ops = EditorChanges::fieldOps($g, $id, $path, $schema, $before, $after, $activeLangs);
        $staging->operateAll($ops, $by);
        $msg = $ops ? 'Modifiche messe in attesa: il sito pubblico non cambia finché non le pubblichi.' : 'Nessuna modifica da mettere in attesa.';
    } elseif (isset($_POST['create_instance'])) {
        $done = $staging->operate(['op' => 'create_entity', 'key' => $g], $by);
        header("Location: $BASE?action=edit&group=" . urlencode($g) . '&id=' . (int) $done['assigned_id']);
        exit;
    } elseif (isset($_POST['create_nested'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        if (count($path) % 2 !== 1) throw new StagingException('Elenco non valido: ricarica la pagina.');
        $array = (string) end($path);
        $isPosts = count($path) === 1 && postsFieldDef($models, $g, $array) !== null;
        $done = $staging->operate(['op' => 'add_item', 'key' => $g, 'id' => $id, 'path' => array_slice($path, 0, -1),
            'array' => $array, 'position' => $isPosts ? 'start' : 'end'], $by);
        $childPath = pathToString([...$path, (string) $done['assigned_id']]);
        header("Location: $BASE?action=edit&group=" . urlencode($g) . "&id=$id&path=" . urlencode($childPath));
        exit;
    } elseif (isset($_POST['delete_instance'])) {
        $staging->operate(['op' => 'remove_entity', 'key' => $g, 'id' => (int) ($_POST['id'] ?? -1)], $by);
        header("Location: $BASE?action=list&group=" . urlencode($g));
        exit;
    } elseif (isset($_POST['delete_nested'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        if (count($path) < 2 || count($path) % 2 !== 0) throw new StagingException('Elemento non valido: ricarica la pagina.');
        $parentPath = array_slice($path, 0, -2);
        $fieldName = (string) $path[count($path) - 2];
        $staging->operate(['op' => 'remove_item', 'key' => $g, 'id' => $id, 'path' => $parentPath,
            'array' => $fieldName, 'item_id' => (int) $path[count($path) - 1]], $by);
        if (isset($_POST['return_posts'])) {
            header("Location: $BASE?action=posts&group=" . urlencode($g) . "&id=$id&field=" . urlencode($fieldName));
            exit;
        }
        $back = pathToString($parentPath);
        header("Location: $BASE?action=edit&group=" . urlencode($g) . "&id=$id" . ($back ? '&path=' . urlencode($back) : ''));
        exit;
    } elseif (isset($_POST['toggle_visibility'])) {
        $visible = ($_POST['visible'] ?? '') === '1';
        $path = parsePath((string) ($_POST['path'] ?? ''));
        $staging->operate(['op' => 'set_hidden', 'key' => $g, 'id' => (int) ($_POST['id'] ?? -1), 'path' => $path, 'hidden' => !$visible], $by);
        $msg = $visible ? 'In attesa: l\'elemento tornerà visibile quando pubblichi.' : 'In attesa: l\'elemento verrà sospeso quando pubblichi.';
        if (!$isAjax) {
            $back = is_string($_POST['return_to'] ?? null) && preg_match('/^\?action=[a-z_]+(&[A-Za-z0-9_=%.\/-]*)*$/', $_POST['return_to'])
                ? $_POST['return_to'] : '?action=list&group=' . urlencode($g);
            header("Location: $BASE$back");
            exit;
        }
    } elseif (isset($_POST['reorder_root'])) {
        $before = array_map(fn($d) => (int) $d['id'], array_values(array_filter($data, fn($d) => ($d['name'] ?? null) === $g)));
        $after = array_map('intval', explode(',', (string) ($_POST['order'] ?? '')));
        $staging->operateAll(array_map(
            fn($move) => ['op' => 'move_entity', 'key' => $g, 'id' => $move[0], 'to' => $move[1]],
            EditorChanges::moves($before, $after)
        ), $by);
        $msg = 'Ordine messo in attesa.';
        if (!$isAjax) {
            header("Location: $BASE?action=list&group=" . urlencode($g));
            exit;
        }
    } elseif (isset($_POST['reorder_nested'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        $rootIdx = findRootIndex($data, $g, $id);
        $copy = $rootIdx >= 0 ? $data[$rootIdx] : null;
        $field = $copy !== null && count($path) % 2 === 1 ? walkEntityPath($copy, $path) : null;
        if (($field['type'] ?? null) !== 'array') throw new StagingException('Elenco non disponibile. Ricarica il contenuto prima di riprovare.');
        $before = array_map(fn($c) => (int) ($c['id'] ?? -1), $field['value']);
        $after = array_map('intval', explode(',', (string) ($_POST['order'] ?? '')));
        $parentPath = array_slice($path, 0, -1);
        $staging->operateAll(array_map(
            fn($move) => ['op' => 'move_item', 'key' => $g, 'id' => $id, 'path' => $parentPath, 'array' => (string) end($path), 'item_id' => $move[0], 'to' => $move[1]],
            EditorChanges::moves($before, $after)
        ), $by);
        $msg = 'Ordine messo in attesa.';
        if (!$isAjax) {
            if (isset($_POST['return_posts']) && count($path) === 1) {
                header("Location: $BASE?action=posts&group=" . urlencode($g) . "&id=$id&field=" . urlencode((string) $path[0]));
                exit;
            }
            $back = pathToString($parentPath);
            header("Location: $BASE?action=edit&group=" . urlencode($g) . "&id=$id" . ($back ? '&path=' . urlencode($back) : ''));
            exit;
        }
    } elseif (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new StagingException('Caricamento non riuscito: riprova.');
        }
        $staging->upload((string) $file['tmp_name'], (string) ($file['name'] ?? ''), $by);
        $msg = 'File caricato fra le modifiche in attesa: sarà pubblico quando pubblichi.';
    } else {
        $error = 'Questa azione non è disponibile mentre prepari modifiche in attesa: passa a «Sito pubblico» per farla.';
    }
} catch (StagingException $e) {
    $msg = '';
    $error = $e->getMessage();
}

// La pagina che segue mostra lo stato aggiornato dello staging
$stagingState = $staging->state();
$data = $stagingState['data'];
$models = $stagingState['model'];

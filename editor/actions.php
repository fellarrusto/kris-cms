<?php
declare(strict_types=1);

use Kris\Entity\MediaStore;
use Kris\Entity\StorageException;
if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * Azioni POST del pannello.
 *
 * Richiede che $data, $models, $activeLangs, i percorsi dei file e
 * $msg / $error siano gia definiti da index.php.
 */

// Difesa in profondita: se questo file venisse eseguito fuori dal flusso
// normale, senza una sessione valida non deve succedere nulla.
if (!kris_is_logged_in()) { http_response_code(403); exit; }

// Nessuna azione senza token valido: impedisce che un'altra pagina faccia
// eseguire modifiche al browser di chi e autenticato.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !kris_csrf_valid($_POST['csrf'] ?? null)) {
    $error = "Sessione scaduta o richiesta non valida: ricarica la pagina e riprova. Nulla e stato modificato.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    // 1. Crea Collezione
    if (isset($_POST['create_collection'])) {
        $name = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['collection_name']));
        if ($name && !isset($models[$name])) {
            $models[$name] = [];
            saveJson($modelFile, $models);
            header("Location: $BASE?action=structure&group=$name");
            exit;
        }
    }
    // 2. Salva Struttura
    if (isset($_POST['save_structure'])) {
        $g = $_POST['group_name'];
        $decoded = json_decode($_POST['schema_json'] ?? '', true);

        if (!is_array($decoded)) {
            // Uno schema illeggibile non deve diventare uno schema vuoto:
            // al primo salvataggio successivo cancellerebbe tutti i campi.
            $error = "Struttura non salvata: i dati inviati non sono validi. Nulla e stato modificato.";
        } else {
            $problems = validateSchema($decoded, $models[$g] ?? []);
            if ($problems) {
                $error = "Struttura non salvata: " . implode(' ', $problems);
            } else {
                $merged = mergeSchemaMetadata($decoded, $models[$g] ?? []);

                // Prima di applicare: quali contenuti gia scritti sparirebbero?
                $impact = isset($_POST['confirm_impact'])
                    ? []
                    : schemaImpact($models[$g] ?? [], $merged, $data, $g);

                if ($impact !== []) {
                    // Non si salva nulla: si mostra l'impatto e si chiede conferma.
                    $pendingImpact = $impact;
                    $pendingSchema = json_encode($merged, JSON_UNESCAPED_UNICODE);
                    $group = $g;
                    $action = 'structure_impact';
                } else {
                    $models[$g] = $merged;
                    saveJson($modelFile, $models);
                    $msg = "Struttura aggiornata con successo.";
                }
            }
        }
    }
    // 3. Elimina Collezione
    if (isset($_POST['delete_collection'])) {
        $g = (string) $_POST['group_name'];
        // Prima veniva rimosso solo il modello: i contenuti restavano
        // nell'archivio e il sito continuava a servirli, pur essendo
        // spariti dal pannello. Si eliminano entrambi (il backup automatico
        // di JsonStore resta come rete di sicurezza).
        $data = array_values(array_filter($data, fn($d) => ($d['name'] ?? null) !== $g));
        saveJson($dataFile, $data);

        unset($models[$g]);
        saveJson($modelFile, $models);
        header("Location: $BASE");
        exit;
    }
    // 4. Salva Entità (root o nested via path)
    if (isset($_POST['save_entity'])) {
        $id = (int) $_POST['id'];
        $g = $_POST['group'];
        $path = parsePath($_POST['path'] ?? '');
        $rootIdx = findRootIndex($data, $g, $id);

        $schema = resolveSchemaAtPath($models, $g, $path);

        if ($rootIdx < 0) {
            // Only root can be created via this path (nested entities are created via create_nested)
            $itemData = applyPostToData([], $schema, $_POST, $activeLangs);
            $data[] = ['id' => $id, 'name' => $g, 'data' => $itemData];
        } else {
            $target = &walkEntityPath($data[$rootIdx], $path);
            // Un percorso dispari indica un CAMPO array, non un contenuto:
            // scriverci dentro 'data' corromperebbe la struttura.
            $valid = $target !== null && count($path) % 2 === 0;
            unset($target);

            if (!$valid) {
                $error = "Percorso non valido: non ho modificato nulla.";
            } else {
                $target = &walkEntityPath($data[$rootIdx], $path);
                $target['data'] = applyPostToData($target['data'] ?? [], $schema, $_POST, $activeLangs);
                unset($target);
            }
        }
        // Si salva e si conferma solo se c'era davvero qualcosa da scrivere.
        if ($error === '') {
            saveJson($dataFile, $data);
            $msg = "Contenuto salvato.";
        }
    }
    // 5. Crea Istanza (root)
    if (isset($_POST['create_instance'])) {
        $g = $_POST['group'];
        $maxId = -1;
        foreach ($data as $d)
            if ($d['name'] === $g)
                $maxId = max($maxId, $d['id']);
        $skeleton = buildSkeleton($models[$g] ?? [], $activeLangs);
        $newId = $maxId + 1;
        $data[] = ['id' => $newId, 'name' => $g, 'data' => $skeleton];
        saveJson($dataFile, $data);
        header("Location: $BASE?action=edit&group=$g&id=$newId");
        exit;
    }
    // 5b. Crea sub-istanza nested (path deve terminare su un field array)
    if (isset($_POST['create_nested'])) {
        $g = $_POST['group'];
        $id = (int) $_POST['id'];
        $path = parsePath($_POST['path'] ?? '');
        $rootIdx = findRootIndex($data, $g, $id);
        if ($rootIdx >= 0 && count($path) % 2 === 1) {
            $schema = resolveSchemaAtPath($models, $g, $path);
            $field = &walkEntityPath($data[$rootIdx], $path);
            if ($field !== null && ($field['type'] ?? null) === 'array') {
                $maxSubId = -1;
                foreach ($field['value'] as $s)
                    $maxSubId = max($maxSubId, (int) ($s['id'] ?? -1));
                $newSubId = $maxSubId + 1;
                $field['value'][] = ['id' => $newSubId, 'data' => buildSkeleton($schema, $activeLangs)];
                saveJson($dataFile, $data);
                $childPath = pathToString([...$path, (string) $newSubId]);
                header("Location: $BASE?action=edit&group=$g&id=$id&path=" . urlencode($childPath));
                exit;
            }
            unset($field);
        }
    }
    // 6. Elimina Istanza (root)
    if (isset($_POST['delete_instance'])) {
        $data = array_filter($data, fn($d) => !($d['name'] == $_POST['group'] && $d['id'] == $_POST['id']));
        saveJson($dataFile, array_values($data));
        header("Location: $BASE?action=list&group=" . $_POST['group']);
        exit;
    }
    // 6b. Elimina sub-istanza nested (path deve terminare su un sub-id)
    if (isset($_POST['delete_nested'])) {
        $g = $_POST['group'];
        $id = (int) $_POST['id'];
        $path = parsePath($_POST['path'] ?? '');
        $rootIdx = findRootIndex($data, $g, $id);
        if ($rootIdx >= 0 && count($path) >= 2 && count($path) % 2 === 0) {
            $parentPath = array_slice($path, 0, -2);
            $fieldName = $path[count($path) - 2];
            $subId = (int) $path[count($path) - 1];
            $parent = &walkEntityPath($data[$rootIdx], $parentPath);
            if ($parent !== null) {
                foreach ($parent['data'] as &$item) {
                    if ($item['name'] === $fieldName && ($item['type'] ?? null) === 'array') {
                        $item['value'] = array_values(array_filter(
                            $item['value'],
                            fn($s) => (int) ($s['id'] ?? -1) !== $subId
                        ));
                        break;
                    }
                }
                unset($item);
            }
            unset($parent);
            saveJson($dataFile, $data);
            $back = pathToString($parentPath);
            header("Location: $BASE?action=edit&group=$g&id=$id" . ($back ? '&path=' . urlencode($back) : ''));
            exit;
        }
    }
    // 6c. Riordina entità root (drag-and-drop)
    if (isset($_POST['reorder_root'])) {
        $g = $_POST['group'];
        $order = array_map('intval', explode(',', $_POST['order'] ?? ''));
        if (!empty($order)) {
            // Estrae le entità del group nell'ordine richiesto, mantiene le altre nella loro posizione relativa
            $byId = [];
            foreach ($data as $d) {
                if ($d['name'] === $g) $byId[(int)$d['id']] = $d;
            }
            $reordered = [];
            $usedIds = [];
            foreach ($order as $oid) {
                // Il controllo su $usedIds scarta i duplicati: un ordine come
                // "0,0" non deve poter duplicare un elemento nell'archivio.
                if (isset($byId[$oid]) && !isset($usedIds[$oid])) {
                    $reordered[] = $byId[$oid];
                    $usedIds[$oid] = true;
                }
            }
            // Aggiunge in coda eventuali entità del group non incluse nell'ordine (safety)
            foreach ($byId as $oid => $d) {
                if (!isset($usedIds[$oid])) $reordered[] = $d;
            }
            // Ricostruisce $data: per ogni entità non-group mantiene posizione, sostituisce le entità del group in ordine
            $newData = [];
            $reIdx = 0;
            foreach ($data as $d) {
                if ($d['name'] === $g) {
                    if (isset($reordered[$reIdx])) $newData[] = $reordered[$reIdx++];
                } else {
                    $newData[] = $d;
                }
            }
            // Aggiunge in coda eventuali rimanenti
            while ($reIdx < count($reordered)) $newData[] = $reordered[$reIdx++];
            $data = $newData;
            saveJson($dataFile, $data);
        }
        $msg = 'Ordine salvato.';
        if (($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '') !== 'reorder') {
            header("Location: $BASE?action=list&group=$g");
            exit;
        }
    }
    // 6d. Riordina sub-entità nested (drag-and-drop)
    if (isset($_POST['reorder_nested'])) {
        $error = 'Elenco non disponibile. Ricarica il contenuto prima di riprovare.';
        $g = $_POST['group'];
        $id = (int) $_POST['id'];
        $path = parsePath($_POST['path'] ?? '');
        $order = array_map('intval', explode(',', $_POST['order'] ?? ''));
        $rootIdx = findRootIndex($data, $g, $id);
        if ($rootIdx >= 0 && !empty($order) && count($path) % 2 === 1) {
            $field = &walkEntityPath($data[$rootIdx], $path);
            if ($field !== null && ($field['type'] ?? null) === 'array') {
                $bySubId = [];
                foreach ($field['value'] as $s) $bySubId[(int)($s['id'] ?? -1)] = $s;
                $reordered = [];
                $usedIds = [];
                foreach ($order as $oid) {
                    // Come per le entita radice: nessun figlio puo comparire due volte.
                    if (isset($bySubId[$oid]) && !isset($usedIds[$oid])) {
                        $reordered[] = $bySubId[$oid];
                        $usedIds[$oid] = true;
                    }
                }
                foreach ($bySubId as $oid => $s) {
                    if (!isset($usedIds[$oid])) $reordered[] = $s;
                }
                $field['value'] = $reordered;
                saveJson($dataFile, $data);
                $error = '';
                $msg = 'Ordine salvato.';
            }
            unset($field);
        }
        $parentPath = array_slice($path, 0, -1);
        $back = pathToString($parentPath);
        if (($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '') !== 'reorder') {
            header("Location: $BASE?action=edit&group=$g&id=$id" . ($back ? '&path=' . urlencode($back) : ''));
            exit;
        }
    }
    // 7. Upload & Settings
    if (isset($_FILES['file'])) {
        // Stesso servizio usato da upload.php: una sola politica su estensioni,
        // dimensioni e nomi, invece dei due comportamenti diversi di prima.
        try {
            (new MediaStore($uploadDir, $uploadUrl))->store($_FILES['file']);
            $msg = "File caricato.";
        } catch (StorageException $e) {
            $error = $e->getMessage();
        }
    }
    if (isset($_POST['save_settings'])) {
        $languages = is_array($_POST['langs'] ?? null)
            ? array_values(array_intersect(array_keys($DEFAULT_LANGS), $_POST['langs'])) : [];
        if ($languages === []) {
            $error = 'Seleziona almeno una lingua. Le impostazioni non sono state modificate.';
        } else {
            saveJson($settingsFile, ['languages' => $languages]);
            $activeLangs = $languages;
            $msg = 'Impostazioni salvate. Le traduzioni delle lingue disattivate sono conservate.';
        }
    }
    // 8. Elimina Media
    if (isset($_POST['delete_media'])) {
        // basename() è fondamentale per la sicurezza (impedisce di cancellare file di sistema con ../)
        $filename = basename($_POST['file_name']); 
        $targetFile = $uploadDir . $filename;
        
        if (file_exists($targetFile)) {
            if (@unlink($targetFile)) $msg = "File eliminato con successo.";
            else $error = 'Non è stato possibile eliminare il file. Riprova.';
        } else {
            $error = "Il file non è più disponibile. La libreria potrebbe essere stata aggiornata.";
        }
    }
  } catch (StorageException $e) {
    // Nessuna conferma positiva se la scrittura non e riuscita: i contenuti
    // sul disco sono rimasti quelli di prima.
    $msg = '';
    $error = 'Non siamo riusciti a salvare. I contenuti non sono stati modificati. '
           . '(' . $e->getMessage() . ')';
  }
}

// Il browser conserva il modulo fino alla conferma. Le normali POST
// continuano a funzionare senza JavaScript.
if ((($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '') === 'save'
        && (isset($_POST['save_entity']) || isset($_POST['save_settings'])))
    || (($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '') === 'reorder'
        && (isset($_POST['reorder_root']) || isset($_POST['reorder_nested'])))) {
    http_response_code($error !== '' ? 422 : 200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $error === '' && $msg !== '', 'message' => $error ?: $msg,
        'csrf' => kris_csrf_token()], JSON_UNESCAPED_UNICODE);
    exit;
}

// View Data
$counts = [];
foreach ($models as $k => $v)
    $counts[$k] = 0;
foreach ($data as $d) {
    if (isset($counts[$d['name']]))
        $counts[$d['name']]++;
}
$images = editorMediaFiles($uploadDir);

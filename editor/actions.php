<?php
declare(strict_types=1);

// Tutte le azioni POST. Richiede che $data, $models, $settings, $activeLangs,
// $dataFile, $modelFile, $settingsFile, $uploadDir siano già definiti in index.php.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

// 1. Crea Collezione
if (isset($_POST['create_collection'])) {
    $name = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['collection_name']));
    if ($name && !isset($models[$name])) {
        $models[$name] = [];
        saveJson($modelFile, $models);
        header("Location: index.php?action=structure&group=$name");
        exit;
    }
}

// 2. Salva Struttura
if (isset($_POST['save_structure'])) {
    $g = $_POST['group_name'];
    $decoded = json_decode($_POST['schema_json'] ?? '[]', true);
    $models[$g] = is_array($decoded) ? $decoded : [];
    saveJson($modelFile, $models);
    $msg = "Struttura aggiornata con successo.";
}

// 3. Elimina Collezione
if (isset($_POST['delete_collection'])) {
    unset($models[$_POST['group_name']]);
    saveJson($modelFile, $models);
    header("Location: index.php");
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
        $itemData = applyPostToData([], $schema, $_POST, $activeLangs);
        $data[] = ['id' => $id, 'name' => $g, 'data' => $itemData];
    } else {
        $target = &walkEntityPath($data[$rootIdx], $path);
        if ($target === null) {
            $msg = "Percorso non valido.";
        } else {
            $target['data'] = applyPostToData($target['data'] ?? [], $schema, $_POST, $activeLangs);
        }
        unset($target);
    }
    saveJson($dataFile, $data);
    $msg = "Contenuto salvato.";
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
    header("Location: index.php?action=edit&group=$g&id=$newId");
    exit;
}

// 5b. Crea sub-istanza nested
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
            header("Location: index.php?action=edit&group=$g&id=$id&path=" . urlencode($childPath));
            exit;
        }
        unset($field);
    }
}

// 6. Elimina Istanza (root)
if (isset($_POST['delete_instance'])) {
    $data = array_filter($data, fn($d) => !($d['name'] == $_POST['group'] && $d['id'] == $_POST['id']));
    saveJson($dataFile, array_values($data));
    header("Location: index.php?action=list&group=" . $_POST['group']);
    exit;
}

// 6b. Elimina sub-istanza nested
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
        header("Location: index.php?action=edit&group=$g&id=$id" . ($back ? '&path=' . urlencode($back) : ''));
        exit;
    }
}

// 7. Upload
if (isset($_FILES['file'])) {
    move_uploaded_file(
        $_FILES['file']['tmp_name'],
        $uploadDir . preg_replace('/[^a-z0-9-_\.]/i', '', basename($_FILES['file']['name']))
    );
}

// 7b. Salva Impostazioni
if (isset($_POST['save_settings'])) {
    saveJson($settingsFile, ['languages' => $_POST['langs'] ?? ['it']]);
    header("Location: index.php?action=settings");
    exit;
}

// 8. Elimina Media
if (isset($_POST['delete_media'])) {
    $filename = basename($_POST['file_name']);
    $targetFile = $uploadDir . $filename;
    if (file_exists($targetFile)) {
        unlink($targetFile);
        $msg = "File eliminato con successo.";
    } else {
        $msg = "Errore: File non trovato.";
    }
}

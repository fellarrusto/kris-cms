<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Leggi l'input
$jsonPayload = file_get_contents('php://input');
$data = json_decode($jsonPayload, true);

// Validazione
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'JSON non valido']);
    exit;
}

if (!isset($data['title']['en']) || !isset($data['title']['it'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Struttura dati mancante']);
    exit;
}

// Percorso file dati
$dataFile = __DIR__ . '/../k_data.json';

// Leggi dati esistenti
$existingData = [];
if (file_exists($dataFile)) {
    $existingData = json_decode(file_get_contents($dataFile), true) ?: [];
}

// Merge dati
$mergedData = array_merge($existingData, $data);

// Salva il file
try {
    file_put_contents(
        $dataFile,
        json_encode($mergedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    );
    echo json_encode(['status' => 'success', 'message' => 'Dati salvati correttamente']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Errore nel salvataggio: ' . $e->getMessage()]);
}
?>
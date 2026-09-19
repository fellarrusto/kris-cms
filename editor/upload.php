<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/auth.php';

use Kris\Entity\MediaStore;
use Kris\Entity\StorageException;

session_start();

if (!kris_is_logged_in()) {
    http_response_code(403);
    exit;
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if (!kris_csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(419);
    echo $isAjax ? json_encode(['error' => 'Sessione scaduta: ricarica la pagina.']) : 'Sessione scaduta.';
    exit;
}

$store = new MediaStore(__DIR__ . '/../assets/uploads/', 'assets/uploads/');

try {
    $url = $store->store($_FILES['file'] ?? []);
} catch (StorageException $e) {
    http_response_code(422);
    if ($isAjax) {
        echo json_encode(['error' => $e->getMessage()]);
    } else {
        header('Location: index.php?action=media&upload_error=' . urlencode($e->getMessage()));
    }
    exit;
}

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['url' => $url]);
} else {
    header('Location: index.php?action=media');
}

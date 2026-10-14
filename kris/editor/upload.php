<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
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

if (kris_maintenance() !== null) {
    http_response_code(423);
    $message = 'È in corso un aggiornamento di Kris: riprova tra qualche minuto.';
    echo $isAjax ? json_encode(['error' => $message]) : $message;
    exit;
}

$store = new MediaStore(KRIS_ROOT . '/assets/uploads/', 'assets/uploads/');
// Con le modifiche in attesa il file va nello staging: diventa pubblico solo alla pubblicazione
$staged = ($_SESSION['kris_editing'] ?? '') === 'staging';

try {
    if ($staged) {
        $file = $_FILES['file'] ?? [];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new StorageException('Caricamento non riuscito: riprova.');
        }
        try {
            $url = (new \Kris\Staging\Staging())->upload((string) $file['tmp_name'], (string) ($file['name'] ?? ''), 'editor: ' . (string) ($_SESSION['kris_user'] ?? ''));
        } catch (\Kris\Staging\StagingException $e) {
            throw new StorageException($e->getMessage());
        }
    } else {
        $url = $store->store($_FILES['file'] ?? []);
    }
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
    echo json_encode(['url' => $url] + ($staged ? ['staged' => true] : []));
} else {
    header('Location: index.php?action=media');
}

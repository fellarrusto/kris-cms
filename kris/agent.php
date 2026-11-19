<?php
declare(strict_types=1);

/**
 * Punto d'ingresso delle richieste di Kris Agent (stub kris-agent.php nella
 * root). Solo richieste firmate dal Worker abbinato: vedi Kris\Agent\Server.
 */

require_once __DIR__ . '/bootstrap.php';

use Kris\Agent\AgentConfig;
use Kris\Agent\Server;
use Kris\Staging\Staging;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    http_response_code(503);
    header('Retry-After: 120');
    echo json_encode(['ok' => false, 'error' => 'Kris si sta aggiornando: riprova tra qualche minuto.']);
    exit;
}

// Indirizzo di caricamento sul dominio del sito (Kris\Agent\UploadSlots): PUT con il file
// (curl -T), oppure il modulo del browser (GET mostra la pagina, POST multipart con «file»)
if (is_string($_GET['upload'] ?? null)) {
    $token = $_GET['upload'];
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $browser = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'text/html');
    if ($method === 'GET') {
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">'
            . '<title>Carica un file · Kris</title><style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 16px;color:#17171a}'
            . 'button{font:inherit;padding:10px 18px;border-radius:999px;border:0;background:#17171a;color:#fff;margin-top:12px}</style></head><body>'
            . '<h1 style="font-weight:500">Carica un file per Kris Agent</h1><p>Il file va fra le modifiche in attesa del sito: diventa pubblico solo quando l\'admin pubblica.</p>'
            . '<form method="post" enctype="multipart/form-data"><input type="file" name="file" required><br><button>Carica</button></form></body></html>';
        exit;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'kris-slot-');
    $name = null;
    try {
        if ($method === 'POST' && isset($_FILES['file']) && is_array($_FILES['file']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK) {
            move_uploaded_file((string) $_FILES['file']['tmp_name'], $tmp);
            $name = (string) $_FILES['file']['name'];
        } elseif ($method === 'PUT' || $method === 'POST') {
            // Il corpo così com'è, fino al limite dei video (MediaStore controlla poi il limite del tipo)
            $in = fopen('php://input', 'rb');
            $out = fopen($tmp, 'wb');
            $copied = stream_copy_to_stream($in, $out, \Kris\Entity\MediaStore::videoMaxBytes() + 1);
            fclose($in);
            fclose($out);
            if ($copied > \Kris\Entity\MediaStore::videoMaxBytes()) $result = ['status' => 413, 'body' => ['ok' => false, 'error' => 'Il file è troppo grande.']];
        } else {
            $result = ['status' => 405, 'body' => ['ok' => false, 'error' => 'Usa PUT con il file, o il modulo della pagina.']];
        }
        $result ??= (new Server(new AgentConfig(), new Staging()))->receiveUpload($token, $tmp, $name);
    } finally {
        @unlink($tmp);
    }
    http_response_code($result['status']);
    if ($browser && $method === 'POST') {
        header('Content-Type: text/html; charset=utf-8');
        $text = $result['body']['ok'] ? 'Caricato. Torna dall\'agente: il file è ' . $result['body']['url'] . '.' : 'Non caricato: ' . $result['body']['error'];
        echo '<!DOCTYPE html><html lang="it"><head><meta charset="UTF-8"><title>Kris</title></head><body style="font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:48px auto;padding:0 16px">'
            . '<p>' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
        exit;
    }
    echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Le intestazioni X-Kris-* da $_SERVER: getallheaders() non c'e con ogni SAPI
$headers = [];
foreach ($_SERVER as $name => $value) {
    if (is_string($value) && str_starts_with($name, 'HTTP_X_KRIS_')) {
        $headers['x-kris-' . strtolower(str_replace('_', '-', substr($name, strlen('HTTP_X_KRIS_'))))] = $value;
    }
}

$result = (new Server(new AgentConfig(), new Staging()))->handle(
    (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
    $headers,
    (string) file_get_contents('php://input', false, null, 0, 12 * 1024 * 1024 + 1)
);
http_response_code($result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

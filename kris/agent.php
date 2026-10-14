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

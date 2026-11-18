<?php
declare(strict_types=1);

/**
 * Chiama l'azione render_page dell'agente in un processo isolato, con la
 * working directory sulla copia del progetto (KRIS_ROOT = la copia): il
 * rendering legge i file del sito tramite KRIS_ROOT, come in produzione.
 *
 * Stampa la risposta in JSON.
 */

parse_str(getenv('KRIS_QUERY') ?: '', $query);
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SCRIPT_NAME'] = '/kris-agent.php';
require getcwd() . '/kris/bootstrap.php';

$server = new Kris\Agent\Server(new Kris\Agent\AgentConfig(KRIS_ROOT), new Kris\Staging\Staging(KRIS_ROOT), KRIS_ROOT);
$method = new ReflectionMethod($server, 'renderPage');
$method->setAccessible(true);
echo json_encode($method->invoke($server, $query));

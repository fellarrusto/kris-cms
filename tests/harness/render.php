<?php
declare(strict_types=1);

/**
 * Rende una pagina pubblica simulando una richiesta, in un processo isolato.
 * Va eseguito con la working directory impostata sulla copia del progetto:
 * index.php e ArrayProcessor risolvono 'vendor/' e 'template/' relativamente a essa.
 *
 * Stampa:  KRIS-STATUS: <codice>\n<html>
 */

// La query arriva dall'environment, non da argv: su Windows escapeshellarg()
// sostituisce i '%' con spazi e corromperebbe i valori urlencoded.
parse_str(getenv('KRIS_QUERY') ?: '', $query);
$_GET = $query;
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();

register_shutdown_function(function (): void {
    $output = ob_get_clean();
    if ($output === false) $output = '';
    $code = http_response_code();
    if (!is_int($code)) $code = 200;
    echo "KRIS-STATUS: {$code}\n" . $output;
});

include 'index.php';

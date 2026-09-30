<?php
declare(strict_types=1);

/**
 * Punto d'ingresso comune del framework: percorsi e autoload.
 *
 * Tutto il framework vive in kris/ e viene sostituito in blocco da un
 * aggiornamento; il sito (template/, assets/, data/, config/) sta nella
 * cartella superiore e non viene mai toccato. Per questo nessun file del
 * framework deve risalire con percorsi relativi: usa KRIS_ROOT per il sito
 * e KRIS_DIR per il framework.
 *
 * Ogni punto d'ingresso lo carica con require_once, cosi funziona sia
 * dagli stub nella root sia se aperto direttamente. L'installer degli
 * aggiornamenti lo include una seconda volta dopo aver sostituito kris/:
 * quindi qui niente dichiarazioni che non tollerino la ripetizione.
 */

if (!defined('KRIS_DIR')) {
    define('KRIS_DIR', __DIR__);
}
if (!defined('KRIS_ROOT')) {
    define('KRIS_ROOT', dirname(__DIR__));
}

// Il framework non ha dipendenze esterne: un autoload PSR-4 basta e toglie
// Composer dall'installazione su hosting raggiungibili solo via FTP.
spl_autoload_register(static function (string $class): void {
    static $namespaces = [
        'Kris\\Entity\\'   => KRIS_DIR . '/core/entity/',
        'Kris\\Template\\' => KRIS_DIR . '/core/template/',
        'Kris\\Update\\'   => KRIS_DIR . '/update/',
    ];
    foreach ($namespaces as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            continue;
        }
        $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
        return;
    }
});

// PHP 8.0 e ancora diffuso sugli hosting condivisi: le poche funzioni della
// 8.1 che il framework usa hanno qui un rimpiazzo equivalente.
if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $i = 0;
        foreach ($array as $key => $_) {
            if ($key !== $i++) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('kris_maintenance')) {
    /**
     * Stato della manutenzione durante un aggiornamento, o null se il sito e
     * aperto. 'stale' vale true quando l'aggiornamento non ha finito entro
     * 15 minuti: la richiesta e stata interrotta e il sito va riaperto.
     */
    function kris_maintenance(): ?array
    {
        $file = KRIS_ROOT . '/data/kris_maintenance.json';
        if (!is_file($file)) {
            return null;
        }
        $info = json_decode((string) @file_get_contents($file), true);
        $info = is_array($info) ? $info : [];
        $info['stale'] = time() - (int) ($info['started_at'] ?? 0) > 900;
        return $info;
    }
}

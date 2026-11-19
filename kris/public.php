<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Kris\Site\PageRenderer;
use Kris\Site\Paths;
use Kris\Site\Router;
use Kris\Staging\Preview;
use Kris\Staging\Staging;

// Durante un aggiornamento il sito risponde 503. Se l'aggiornamento si e
// interrotto (manutenzione scaduta) il sito torna visibile comunque.
$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    require KRIS_DIR . '/maintenance.php';
    exit;
}

// Anteprima a indirizzo proprio: /kris-admin/preview/<indirizzo della pagina> è quella pagina
// in anteprima, anche se nessun link la raggiunge. Vale come ?kris_preview=1 (serve l'editor aperto).
$previewPrefix = \Kris\Site\Router::basePath($_SERVER) . '/kris-admin/preview';
$requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
if ($requestPath === $previewPrefix || str_starts_with($requestPath, $previewPrefix . '/')) {
    $_GET['kris_preview'] = '1';
    $tail = substr($requestPath, strlen($previewPrefix)) ?: '/';
    $queryString = (string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_QUERY);
    $_SERVER['REQUEST_URI'] = \Kris\Site\Router::basePath($_SERVER) . $tail . ($queryString !== '' ? '?' . $queryString : '');
}
// La lingua arriva dall'URL: va confrontata con quelle configurate, non
// usata cosi com'e (finisce nei contenuti e negli attributi delle pagine).
[$lang] = PageRenderer::language($_GET);

// Lavori in corso: i visitatori ricevono la pagina di lavorazione (503); chi ha l'editor aperto vede il sito
$wip = PageRenderer::workInProgress($lang);
if ($wip !== null && !Preview::isAdmin()) {
    http_response_code(503);
    header('Retry-After: 3600');
    header('Cache-Control: no-store');
    echo $wip;
    exit;
}
// Anteprima dello staging: solo con una sessione dell'editor. Senza, la
// pagina non esiste: chi riceve il link dell'anteprima vede la 404.
$preview = null;
if (($_GET['kris_preview'] ?? null) === '0' && Preview::modeCookie($_COOKIE)) {
    // «Esci» dall'anteprima: da qui in poi il sito pubblico
    Preview::rememberMode(false);
}
$previewAdmin = Preview::requested($_GET, $_COOKIE) && Preview::isAdmin();
if (Preview::requested($_GET, $_COOKIE) && !$previewAdmin) {
    if (Preview::explicit($_GET)) {
        http_response_code(404);
        echo PageRenderer::notFound($lang);
        exit;
    }
    // Solo il cookie della modalità, senza più la sessione: il sito pubblico, non una 404
    Preview::rememberMode(false);
}
if ($previewAdmin) {
    $staging = new Staging();
    if (isset($_GET['kris_preview_asset'])) {
        Preview::serveAsset($staging, (string) $_GET['kris_preview_asset']);
        exit;
    }
    $preview = $staging;
    Preview::rememberMode(true);
    Preview::headers($staging);
    if ($staging->exists()) {
        // I contenuti del sito possono essere cambiati dall'ultima operazione: l'anteprima riparte da quelli
        $staging->rebuild();
        Paths::useOverlay($staging->dir(), $staging->deletedFiles());
    }
}

// Indirizzi leggibili: il percorso (/en/progetto/3) diventa i parametri della pagina;
// i vecchi indirizzi index.php?page=… rispondono con un 301 al percorso nuovo.
$query = $_GET;
// Lingua scelta dal visitatore (?ln=): la ricorda la lingua automatica, prima di qualunque redirect
if (\Kris\Template\Seo::config(KRIS_ROOT)['auto_language'] && is_string($_GET['ln'] ?? null) && in_array($_GET['ln'], PageRenderer::language([])[1], true) && !headers_sent()) {
    setcookie('kris_lang', $_GET['ln'], ['expires' => time() + 180 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['kris_lang'] = $_GET['ln'];
}
$routes = PageRenderer::routes();
$basePath = Router::basePath($_SERVER);

// Reindirizzamenti di config/seo.json: vecchi indirizzi, QR già stampati, cambi di struttura
$requested = '/' . ltrim(substr((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), strlen($basePath)), '/');
$redirects = \Kris\Template\Seo::config(KRIS_ROOT)['redirects'];
$to = $redirects[$requested] ?? $redirects[rtrim($requested, '/')] ?? null;
if ($to !== null && $preview === null) {
    header('Location: ' . (str_starts_with($to, '/') ? $basePath . $to : $to), true, 301);
    exit;
}
if ($routes !== null) {
    [, $langs] = PageRenderer::language([]);
    $requestPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $relative = '/' . ltrim(substr($requestPath, strlen($basePath)), '/');
    $pageParams = ['page', 'key', 'id', 'path', 'ln'];
    if ($relative !== '/' && $relative !== '/index.php') {
        $resolved = Router::resolve($routes, $relative, $langs, $langs[0]);
        // Il cambio lingua (kris/core/scripts/script.js) aggiunge ?ln= all'indirizzo: si va alla stessa pagina nell'altra lingua
        if ($resolved !== null && is_string($query['ln'] ?? null) && in_array($query['ln'], $langs, true) && $query['ln'] !== ($resolved['ln'] ?? $langs[0])) {
            $target = Router::path($routes, $resolved['page'], $resolved['key'] ?? 'homepage', (int) ($resolved['id'] ?? 0),
                ($resolved['path'] ?? '') === '' ? [] : explode('/', $resolved['path']), $query['ln'], $langs[0]);
            if ($target !== null) {
                $rest = array_diff_key($query, array_flip($pageParams));
                header('Location: ' . $basePath . $target . ($rest ? '?' . http_build_query($rest) : ''), true, 302);
                exit;
            }
        }
        // Un percorso che non è di nessuna pagina è una 404
        $query = ($resolved ?? ['page' => '404']) + array_diff_key($query, array_flip($pageParams));
    } elseif ($preview === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && array_intersect_key($query, array_flip($pageParams))) {
        $pretty = Router::path(
            $routes,
            (string) ($query['page'] ?? 'homepage'), (string) ($query['key'] ?? 'homepage'), (int) ($query['id'] ?? 0),
            ($query['path'] ?? '') === '' ? [] : explode('/', (string) $query['path']),
            (string) ($query['ln'] ?? $langs[0]), $langs[0]
        );
        if ($pretty !== null) {
            $rest = array_diff_key($query, array_flip($pageParams));
            header('Location: ' . $basePath . $pretty . ($rest ? '?' . http_build_query($rest) : ''), true, 301);
            exit;
        }
    }
}

// Lingua automatica (seo.json, auto_language): la homepage senza lingua segue la scelta già fatta
// dal visitatore (cookie) o la lingua del browser; i motori di ricerca vedono sempre la principale.
if (\Kris\Template\Seo::config(KRIS_ROOT)['auto_language'] && $preview === null) {
    [$current, $langs] = PageRenderer::language($query);
    $explicit = isset($query['ln']) || ($routes !== null && isset($resolved['ln']));
    if ($explicit) {
        if (!headers_sent()) setcookie('kris_lang', $current, ['expires' => time() + 180 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    } elseif (($query['page'] ?? 'homepage') === 'homepage' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && !preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''))) {
        $wanted = is_string($_COOKIE['kris_lang'] ?? null) && in_array($_COOKIE['kris_lang'], $langs, true) ? $_COOKIE['kris_lang'] : null;
        if ($wanted === null) {
            foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $part) {
                $code = strtolower(substr(trim(explode(';', $part)[0]), 0, 2));
                if (in_array($code, $langs, true)) { $wanted = $code; break; }
            }
        }
        if ($wanted !== null && $wanted !== $langs[0]) {
            $target = $routes !== null ? $basePath . Router::path($routes, 'homepage', 'homepage', 0, [], $wanted, $langs[0]) : $basePath . '/?ln=' . rawurlencode($wanted);
            header('Vary: Accept-Language, Cookie');
            header('Location: ' . $target, true, 302);
            exit;
        }
    }
}

$page = PageRenderer::render($query, $_SERVER);
http_response_code($page['status']);
$html = $preview !== null
    ? Preview::decorate($page['html'], $preview, 'kris-admin/index.php#/da-pubblicare', Preview::exitUrl($_GET))
    : $page['html'];
echo $routes !== null ? Router::rewriteLinks($html, $routes, $basePath, PageRenderer::language([])[1][0]) : $html;

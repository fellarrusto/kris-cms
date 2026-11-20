<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Kris\Site\PageRenderer;
use Kris\Site\Paths;
use Kris\Site\Router;
use Kris\Staging\Preview;
use Kris\Staging\Staging;

// Ogni redirect della pagina passa da qui: i test leggono la destinazione (in CLI header() non la conserva)
$redirect = static function (string $url, int $code): never {
    $GLOBALS['kris_location'] = $url;
    header('Location: ' . $url, true, $code);
    exit;
};

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
// Chi ha l'editor aperto vede il sito; con ?kris_wip_preview=1 vede la pagina di lavorazione, anche spenta
if (Preview::isAdmin() && ($_GET['kris_wip_preview'] ?? null) === '1' && ($wipPreview = PageRenderer::workInProgress($lang, true)) !== null) {
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo $wipPreview;
    exit;
}
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
    // Nell'anteprima i plugin non girano: i loro script andrebbero approvati come quelli del tema
    \Kris\Plugin\Plugins::suspend();
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

// Endpoint dei plugin: index.php?kris_plugin=<plugin>&endpoint=<nome>, o /kris-plugin/<plugin>/<nome>
$pluginPath = substr($requestPath, strlen(Router::basePath($_SERVER)));
if (preg_match('#^/kris-plugin/([a-z0-9_]+)/([a-z0-9_-]+)/?$#', $pluginPath, $pm) === 1) {
    $_GET['kris_plugin'] = $pm[1];
    $_GET['endpoint'] = $pm[2];
}
if (is_string($_GET['kris_plugin'] ?? null) && is_string($_GET['endpoint'] ?? null) && $preview === null) {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $size = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    $raw = $method === 'POST' && $size <= \Kris\Plugin\Plugins::MAX_BODY ? (string) file_get_contents('php://input', false, null, 0, \Kris\Plugin\Plugins::MAX_BODY + 1) : '';
    $size = max($size, strlen($raw));
    $json = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') ? json_decode($raw, true) : null;
    $query = array_diff_key($_GET, ['kris_plugin' => 1, 'endpoint' => 1]);
    $response = \Kris\Plugin\Plugins::endpoint($_GET['kris_plugin'], $_GET['endpoint'], [
        'method' => $method, 'query' => $query, 'body' => is_array($json) ? $json : $_POST, 'lang' => $lang,
        'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''), 'files' => $_FILES, 'size' => $size,
    ]);
    http_response_code($response['status']);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if (isset($response['location'])) {
        header('Location: ' . (str_starts_with($response['location'], Router::basePath($_SERVER) . '/') ? '' : Router::basePath($_SERVER)) . $response['location']);
    } elseif (array_key_exists('text', $response)) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $response['text'];
    } else {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($response['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    exit;
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
// Una destinazione /… è del sito: //… o /\… porterebbero altrove e non valgono
if (is_string($to) && str_starts_with($to, '/') && preg_match('#^/[/\\\\]#', $to) === 1) $to = null;
if ($to !== null && $preview === null) {
    $redirect((str_starts_with($to, '/') ? $basePath . $to : $to), 301);
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
                $redirect($basePath . $target . ($rest ? '?' . http_build_query($rest) : ''), 302);
            }
        }
        // Un percorso che non è di nessuna pagina è una 404
        $query = ($resolved ?? ['page' => '404']) + array_diff_key($query, array_flip($pageParams));
    } elseif ($preview === null && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && array_intersect_key($query, array_flip($pageParams))) {
        $pretty = Router::path(
            $routes,
            (string) ($query['page'] ?? 'homepage'), (string) ($query['key'] ?? 'homepage'), (int) ($query['id'] ?? 0),
            ($query['path'] ?? '') === '' ? [] : explode('/', (string) $query['path']),
            in_array($query['ln'] ?? null, $langs, true) ? (string) $query['ln'] : $langs[0], $langs[0]
        );
        if ($pretty !== null) {
            $rest = array_diff_key($query, array_flip($pageParams));
            $redirect($basePath . $pretty . ($rest ? '?' . http_build_query($rest) : ''), 301);
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
            $redirect($target, 302);
        }
    }
}

$page = PageRenderer::render($query, $_SERVER);
http_response_code($page['status']);
$html = $preview !== null
    ? Preview::decorate($page['html'], $preview, 'kris-admin/index.php#/da-pubblicare', Preview::exitUrl($_GET))
    : $page['html'];
// Lavori in corso accesi e editor aperto: il sito si vede, ma una striscia in cima ricorda che i visitatori no
if ($wip !== null && $preview === null && Preview::isAdmin()) {
    $html = Preview::notice($html, 'Lavori in corso', 'I visitatori vedono la pagina di lavorazione; tu il sito, perché hai l\'editor aperto.',
        [['Vedi la pagina', $basePath . '/index.php?kris_wip_preview=1&ln=' . rawurlencode($lang)]]);
}
echo $routes !== null ? Router::rewriteLinks($html, $routes, $basePath, PageRenderer::language([])[1][0]) : $html;

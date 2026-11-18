<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use Kris\Site\PageRenderer;
use Kris\Site\Paths;
use Kris\Staging\Preview;
use Kris\Staging\Staging;

// Durante un aggiornamento il sito risponde 503. Se l'aggiornamento si e
// interrotto (manutenzione scaduta) il sito torna visibile comunque.
$maintenance = kris_maintenance();
if ($maintenance !== null && !$maintenance['stale']) {
    require KRIS_DIR . '/maintenance.php';
    exit;
}

// La lingua arriva dall'URL: va confrontata con quelle configurate, non
// usata cosi com'e (finisce nei contenuti e negli attributi delle pagine).
[$lang] = PageRenderer::language($_GET);

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

$page = PageRenderer::render($_GET, $_SERVER);
http_response_code($page['status']);
echo $preview !== null
    ? Preview::decorate($page['html'], $preview, 'editor/index.php?action=staging', Preview::exitUrl($_GET))
    : $page['html'];

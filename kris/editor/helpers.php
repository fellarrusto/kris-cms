<?php
declare(strict_types=1);

if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * Funzioni dell'editor che non dipendono dai dati: testo, file della libreria,
 * pagina di errore dell'archivio. Il formato dei dati è in model.php, l'API in
 * api.php, l'interfaccia nell'app Vue (admin-src/).
 */

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * I file caricati fra le modifiche in attesa e non ancora pubblici: l'editor li
 * mostra attraverso l'anteprima. Si impostano una volta, poi si leggono.
 *
 * @param string[]|null $names
 * @return array<string, true>
 */
function editorStagedUploads(?array $names = null): array
{
    static $set = [];
    if ($names !== null) $set = array_fill_keys($names, true);
    return $set;
}

/** Che cosa è un file della libreria, dall'estensione: image, video o pdf. */
function editorMediaKind(string $name): string
{
    $ext = strtolower(pathinfo((string) parse_url($name, PHP_URL_PATH), PATHINFO_EXTENSION));
    return $ext === 'pdf' ? 'pdf' : (in_array($ext, \Kris\Entity\MediaStore::VIDEO_EXTENSIONS, true) ? 'video' : 'image');
}

/** I file della libreria, i più recenti prima, senza le misure ridotte delle immagini. */
function editorMediaFiles(string $directory): array
{
    $extensions = [...\Kris\Entity\MediaStore::allowedExtensions(), 'svg'];
    $files = array_values(array_filter(glob($directory . '*') ?: [],
        fn($file) => is_file($file) && in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), $extensions, true)
            && !\Kris\Entity\MediaStore::isVariant(basename($file))));
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    return $files;
}

/** Indirizzo con cui l'editor (in /kris-admin/) mostra un file del sito o dello staging. */
function editorMediaSource(string $url): string
{
    if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.-]*:|//)~i', $url)) {
        return preg_match('~^https?://~i', $url) ? $url : '';
    }
    if (preg_match('~^assets/uploads/([A-Za-z0-9._-]+)$~', $url, $m) && isset(editorStagedUploads()[$m[1]])) {
        return '../' . \Kris\Staging\Preview::assetUrl('uploads/' . rawurlencode($m[1]));
    }
    return str_starts_with($url, '/') || str_starts_with($url, '../') ? $url : '../' . $url;
}

/** Pagina di errore bloccante: mostrata quando i dati non sono leggibili. */
function renderStorageError(string $detail): void // termina sempre con exit
{
    http_response_code(500);
    $detail = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="it"><head><meta charset="UTF-8">
    <title>Archivio non disponibile - Kris</title>
    <style>
        body { font-family: Geist, system-ui, sans-serif; background:#EDEDEA; color:#17171A;
               display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; padding:16px; box-sizing:border-box; }
        .box { background:#fff; padding:32px; border-radius:20px; max-width:560px; }
        h1 { margin:0 0 12px; font-size:22px; font-weight:500; }
        p { color:#3A3936; line-height:1.55; }
        code { background:#F1F0EC; padding:8px 10px; border-radius:10px; font-size:13px;
               display:block; margin-top:14px; color:#7F1D1D; word-break:break-all; }
    </style></head><body><div class="box">
        <h1>I contenuti non sono disponibili</h1>
        <p><strong>Non è stato modificato nulla.</strong> L'archivio dei contenuti non è
        leggibile, quindi il pannello si è fermato per non sovrascriverlo.</p>
        <p>Le copie di sicurezza si trovano in <code>data/backups/</code>: ripristina la più
        recente rinominandola al posto del file danneggiato, poi ricarica questa pagina.</p>
        <code>{$detail}</code>
    </div></body></html>
    HTML;
    exit;
}

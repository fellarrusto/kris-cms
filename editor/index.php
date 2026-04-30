<?php
declare(strict_types=1);

/**
 * KRIS 2 CMS - Professional UI Edition (Secured)
 * File: /editor/index.php  —  Bootstrap & Router
 */

session_start();

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/auth.php';      // blocca l'esecuzione se non loggato

// --- CONFIGURAZIONE ---
$dataFile     = __DIR__ . '/../data/k_data.json';
$modelFile    = __DIR__ . '/../data/k_model.json';
$settingsFile = __DIR__ . '/../data/cms_settings.json';
$uploadDir    = __DIR__ . '/../assets/uploads/';
$uploadUrl    = 'assets/uploads/';

$DEFAULT_LANGS = [
    'it' => 'Italiano',
    'en' => 'English',
    'es' => 'Español',
    'fr' => 'Français',
    'de' => 'Deutsch',
];

// Init cartelle/file
if (!is_dir($uploadDir))      mkdir($uploadDir, 0777, true);
if (!file_exists($modelFile)) file_put_contents($modelFile, '{}');
if (!file_exists($dataFile))  file_put_contents($dataFile, '[]');

// --- DATI ---
$data       = getJson($dataFile);
$models     = getJson($modelFile);
$settings   = getJson($settingsFile, ['languages' => ['it', 'en']]);
$activeLangs = $settings['languages'];

$action = $_GET['action'] ?? 'dashboard';
$group  = $_GET['group']  ?? null;
$msg    = '';

// --- AZIONI POST ---
require_once __DIR__ . '/actions.php';

// View Data (calcolato dopo le azioni per avere i dati aggiornati)
$counts = [];
foreach ($models as $k => $v) $counts[$k] = 0;
foreach ($data as $d) {
    if (isset($counts[$d['name']])) $counts[$d['name']]++;
}
$images = glob($uploadDir . '*.{jpg,png,svg,webp,jpeg,gif}', GLOB_BRACE);

// --- LAYOUT ---
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kris 2 CMS</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/tinymce/6.8.2/tinymce.min.js" referrerpolicy="origin"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars(rtrim(dirname($_SERVER['PHP_SELF']), '/') . '/css/style.css') ?>">
</head>
<body>

<?php require_once __DIR__ . '/partials/sidebar.php'; ?>

<main>
    <?php if ($msg): ?>
        <div class="alert">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                <polyline points="22 4 12 14.01 9 11.01"></polyline>
            </svg>
            <?= $msg ?>
        </div>
    <?php endif; ?>

    <?php
    $viewMap = [
        'dashboard' => __DIR__ . '/views/dashboard.php',
        'list'      => __DIR__ . '/views/list.php',
        'structure' => __DIR__ . '/views/structure.php',
        'edit'      => __DIR__ . '/views/edit.php',
        'media'     => __DIR__ . '/views/media.php',
        'settings'  => __DIR__ . '/views/settings.php',
    ];
    $viewFile = $viewMap[$action] ?? $viewMap['dashboard'];
    require $viewFile;
    ?>
</main>

<?php require_once __DIR__ . '/partials/media_overlay.php'; ?>

<script>
    let tgt = null;
    let tinymceCallback = null;
    let hasUnsavedChanges = false;

    function markAsDirty() { hasUnsavedChanges = true; }

    function confirmExit(e) {
        if (hasUnsavedChanges) {
            const choice = confirm("Hai modifiche non salvate.\nSe esci ora, andranno perse.\n\nSei sicuro di voler uscire?");
            if (!choice) { e.preventDefault(); return false; }
        }
        return true;
    }

    function pickMedia(id) {
        tgt = id;
        tinymceCallback = null;
        document.getElementById('mediaOverlay').style.display = 'flex';
    }

    function openCmsMediaPicker(callback, value, meta) {
        tinymceCallback = callback;
        tgt = null;
        document.getElementById('mediaOverlay').style.display = 'flex';
    }

    function selectMedia(u) {
        if (tinymceCallback) {
            tinymceCallback(u, { title: u.split('/').pop() });
            tinymceCallback = null;
            markAsDirty();
        } else if (tgt) {
            document.getElementById(tgt).value = u;
            var container = document.getElementById(tgt).closest('.tab-content');
            var preview = container.querySelector('img');
            if (preview) {
                preview.src = '../' + u;
            } else {
                var div = document.createElement('div');
                div.style.cssText = 'margin-top:10px;padding:5px;border:1px solid var(--border);border-radius:6px;display:inline-block;background:white';
                div.innerHTML = '<img src="../' + u + '" style="height:100px;display:block;object-fit:cover">';
                container.appendChild(div);
            }
            markAsDirty();
        }
        document.getElementById('mediaOverlay').style.display = 'none';
    }

    function openTab(el, cid, grp) {
        document.querySelectorAll('.group-' + grp).forEach(x => x.classList.remove('active'));
        document.getElementById(cid).classList.add('active');
        el.parentElement.querySelectorAll('.tab-btn').forEach(x => x.classList.remove('active'));
        el.classList.add('active');
    }

    function uploadFromOverlay(input) {
        if (!input.files[0]) return;
        const form = new FormData();
        form.append('file', input.files[0]);
        fetch('./upload.php', {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            const card = document.createElement('div');
            card.onclick = () => selectMedia(data.url);
            card.style.cssText = 'background:white;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid transparent;box-shadow:0 1px 2px rgba(0,0,0,0.1)';
            card.innerHTML = '<img src="../' + data.url + '" style="width:100%;aspect-ratio:1;object-fit:cover"><div style="padding:5px;font-size:0.7rem;text-align:center;overflow:hidden;white-space:nowrap">' + data.url.split('/').pop() + '</div>';
            document.getElementById('mediaGrid').children[1].after(card);
            input.value = '';
        });
    }

    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll('form.card input, form.card textarea, form.card select').forEach(el => {
            el.addEventListener('input', markAsDirty);
            el.addEventListener('change', markAsDirty);
        });

        const form = document.querySelector('form.card');
        if (form) form.addEventListener('submit', () => { hasUnsavedChanges = false; });

        window.addEventListener('beforeunload', function(e) {
            if (hasUnsavedChanges) e.preventDefault();
        });

        if (document.querySelector('.richtext')) {
            tinymce.init({
                selector: '.richtext',
                height: 400,
                menubar: false,
                plugins: 'image link lists code fullscreen',
                toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link image | code fullscreen',
                file_picker_callback: openCmsMediaPicker,
                content_style: 'body { font-family:Segoe UI,Arial,sans-serif; font-size:14px }',
                setup: function(editor) {
                    let isReady = false;
                    editor.on('init', function() { setTimeout(() => { isReady = true; }, 300); });
                    editor.on('change', function() { if (isReady) markAsDirty(); });
                    editor.on('keyup',  function() { if (isReady) markAsDirty(); });
                }
            });
        }
    });
</script>
</body>
</html>

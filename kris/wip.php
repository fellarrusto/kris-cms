<?php
// Pagina «Lavori in corso» di serie, per i siti senza template/kris_wip.html.
// $wip: titolo e testo della sezione Lavori in corso; $lang: lingua della pagina.
$wip = isset($wip) && is_array($wip) ? $wip : [];
$say = fn(string $key, string $default): string => htmlspecialchars($wip[$key] ?? $default, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="<?= htmlspecialchars((string) ($lang ?? 'it'), ENT_QUOTES, 'UTF-8') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $say('title', 'Sito in lavorazione') ?></title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.6 system-ui,sans-serif;background:#f6f6f4;color:#1b1b1b;padding:24px;box-sizing:border-box;text-align:center}main{max-width:520px}h1{font-size:28px;margin:0 0 12px}@media(prefers-color-scheme:dark){body{background:#151515;color:#eee}}</style>
</head>
<body><main><h1><?= $say('title', 'Sito in lavorazione') ?></h1><p><?= $say('text', 'Torna a trovarci presto.') ?></p></main></body>
</html>

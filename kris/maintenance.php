<?php
declare(strict_types=1);

// Pagina mostrata ai visitatori durante un aggiornamento del framework.
// 503 con Retry-After: i motori di ricerca capiscono che e temporaneo.
http_response_code(503);
header('Retry-After: 120');
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>Sito in aggiornamento</title>
    <style>
        body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px; box-sizing:border-box;
               font-family:-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background:#f7f7f4; color:#202b27; }
        main { max-width:420px; text-align:center; }
        h1 { font-size:22px; margin:0 0 10px; }
        p { margin:0; color:#63716a; line-height:1.6; }
    </style>
</head>
<body>
<main>
    <h1>Stiamo aggiornando il sito</h1>
    <p>Torna tra un paio di minuti: sarà tutto come prima.</p>
</main>
</body>
</html>

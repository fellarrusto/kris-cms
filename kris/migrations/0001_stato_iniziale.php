<?php
// Registra il sito nel file di stato con il formato dei dati della 1.0.0.
// I contenuti non cambiano: e il punto di partenza delle migrazioni future.
return [
    'version'     => 1,
    'description' => 'Registra il formato iniziale dei contenuti.',
    'auto'        => true,
    'up'          => static fn(array $files): array => $files,
];

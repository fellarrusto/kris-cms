<?php
// Da qui i dati possono contenere "hidden": true sugli elementi (entita root
// e figli delle liste): restano nei dati ma il sito non li mostra. I contenuti
// non cambiano; la versione dei dati sale perche un framework precedente
// mostrerebbe gli elementi sospesi, e cosi l'editor se ne accorge.
return [
    'version'     => 3,
    'description' => 'Elementi sospesi: il sito non mostra quelli con "hidden": true.',
    'auto'        => true,
    'up'          => static fn(array $files): array => $files,
];

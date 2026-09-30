<?php
declare(strict_types=1);

/**
 * Genera le due coppie di chiavi per firmare le release: principale e riserva.
 *
 * Uso:  php -d extension=sodium tools/keygen.php <cartella-fuori-dalla-repo>
 *
 * Scrive nella cartella i file principale.key e riserva.key (chiavi segrete)
 * e riscrive kris/update/keys.php con le due chiavi pubbliche.
 *
 * Le chiavi segrete non devono MAI finire nella repository ne nella CI.
 * La riserva va spostata offline (chiavetta, password manager): serve solo
 * se la principale va persa o viene compromessa.
 *
 * Va eseguito una volta sola, prima della 1.0.0: i siti installati si fidano
 * delle chiavi pubbliche presenti nella versione che hanno.
 */

require __DIR__ . '/../kris/bootstrap.php';

use Kris\Update\Signature;

$dir = $argv[1] ?? '';
if ($dir === '') {
    fwrite(STDERR, "Uso: php -d extension=sodium tools/keygen.php <cartella-fuori-dalla-repo>\n");
    exit(1);
}
$repo = realpath(__DIR__ . '/..');
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Non riesco a creare {$dir}\n");
    exit(1);
}
$dir = realpath($dir);
if (str_starts_with(strtolower($dir), strtolower($repo))) {
    fwrite(STDERR, "La cartella delle chiavi deve stare FUORI dalla repository.\n");
    exit(1);
}
foreach (['principale', 'riserva'] as $name) {
    if (is_file("$dir/$name.key")) {
        fwrite(STDERR, "Esiste gia $dir/$name.key: non la sovrascrivo.\n");
        exit(1);
    }
}

$public = [];
foreach (['principale', 'riserva'] as $name) {
    $pair = Signature::generateKeyPair();
    file_put_contents("$dir/$name.key", $pair['secret'] . "\n");
    @chmod("$dir/$name.key", 0600);
    $public[$name] = $pair['public'];
}

$php = "<?php\n"
    . "// Chiavi pubbliche con cui si verificano i pacchetti e il canale delle release.\n"
    . "// Generate con tools/keygen.php. Le chiavi segrete non stanno nella repository.\n"
    . "// Un sito si fida delle chiavi della versione che ha installato: cambiarle qui\n"
    . "// vale solo per i siti che riceveranno questa versione.\n"
    . "return " . var_export($public, true) . ";\n";
file_put_contents(__DIR__ . '/../kris/update/keys.php', $php);

echo "Chiavi segrete scritte in {$dir}\n";
echo "Chiavi pubbliche scritte in kris/update/keys.php\n";
echo "Sposta subito riserva.key in un posto offline.\n";

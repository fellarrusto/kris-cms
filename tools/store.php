<?php
declare(strict_types=1);

/**
 * L'archivio dei contenuti di un sito Kris: quale adapter usa e passaggio
 * dall'uno all'altro (docs/2.0/specifica.md, 1.5).
 *
 *   php tools/store.php status
 *   php tools/store.php convert --to=pdo --dsn="sqlite:data/kris.sqlite"
 *   php tools/store.php convert --to=pdo --dsn="mysql:host=localhost;dbname=sito;charset=utf8mb4" --user=sito --password=… [--prefix=kris_]
 *   php tools/store.php convert --to=json
 *
 * Il passaggio copia tutti i documenti, li rilegge dall'archivio nuovo per
 * confrontarli e solo allora scrive config/store.php. L'archivio vecchio
 * resta com'era: è la copia di sicurezza. Un percorso SQLite relativo vale
 * dalla root del sito. --root=<cartella> lavora su un altro sito.
 */

$options = [];
$positional = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        $positional[] = $arg;
    }
}
if (isset($options['root'])) {
    define('KRIS_ROOT', rtrim((string) realpath((string) $options['root']), '/\\'));
}
require_once dirname(__DIR__) . '/kris/bootstrap.php';

use Kris\Store\FileStore;
use Kris\Store\PdoStore;
use Kris\Store\Stores;

function fail(string $message): never
{
    fwrite(STDERR, $message . "\n");
    exit(1);
}

$command = $positional[0] ?? 'status';
$from = Stores::driver(KRIS_ROOT);

try {
    if ($command === 'status') {
        $docs = Stores::site()->export();
        echo "Archivio: {$from}\n";
        foreach ($docs as $doc => $value) echo "  {$doc}: " . count($value) . " elementi\n";
        exit(0);
    }
    if ($command !== 'convert') fail("Comando sconosciuto: {$command}. Usa status o convert.");

    $to = (string) ($options['to'] ?? '');
    if (!in_array($to, ['json', 'pdo'], true)) fail('Indica --to=json oppure --to=pdo.');
    if ($to === 'json' && $from === 'json') fail('Il sito usa già i file JSON.');

    $config = ['driver' => $to];
    if ($to === 'pdo') {
        if (!is_string($options['dsn'] ?? null) || $options['dsn'] === '') fail('Indica il database con --dsn=…');
        $config += ['dsn' => $options['dsn']];
        foreach (['user', 'password', 'prefix'] as $key) {
            if (is_string($options[$key] ?? null)) $config[$key] = $options[$key];
        }
    }

    $documents = Stores::site()->export();
    $target = $to === 'pdo' ? new PdoStore(KRIS_ROOT, $config) : new FileStore(KRIS_ROOT);
    $target->import($documents);
    if ($target->export() != $documents) fail("L'archivio nuovo non restituisce gli stessi documenti: config/store.php non è stato cambiato.");

    $file = KRIS_ROOT . '/config/store.php';
    $php = "<?php\n// Archivio dei contenuti (tools/store.php). Privato: contiene l'accesso al database.\nreturn " . var_export($config, true) . ";\n";
    if (@file_put_contents($file, $php) === false) fail("Non riesco a scrivere {$file}.");
    @chmod($file, 0600);
    echo "Fatto: " . count($documents) . " documenti da {$from} a {$to}. L'archivio {$from} resta com'era.\n";
} catch (Throwable $e) {
    fail('Passaggio non riuscito: ' . $e->getMessage());
}

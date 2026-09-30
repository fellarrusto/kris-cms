<?php
declare(strict_types=1);

namespace Kris\Update;

use ZipArchive;

/**
 * Pacchetto di aggiornamento: uno zip che contiene solo la cartella kris/.
 *
 * Il pacchetto si verifica da solo, cosi vale anche quando viene caricato a
 * mano senza passare dal canale:
 *
 *   kris/RELEASE.json   versione, requisiti, versione di partenza minima, note
 *   kris/MANIFEST.json  {"version": "...", "files": {"kris/...": "<sha256>"}}
 *   kris/MANIFEST.sig   firma Ed25519 (base64) di MANIFEST.json
 *
 * Verificare la firma del manifest e poi l'hash di ogni file equivale a
 * verificare tutto il pacchetto. I file non elencati sono un errore.
 */
final class Package
{
    public const MAX_ZIP_BYTES = 20 * 1024 * 1024;
    private const MAX_ENTRIES = 3000;
    private const MAX_UNPACKED_BYTES = 80 * 1024 * 1024;
    private const MANIFEST = 'kris/MANIFEST.json';
    private const SIGNATURE = 'kris/MANIFEST.sig';

    /**
     * Estrae lo zip in $destDir (che non deve esistere), rifiutando qualunque
     * voce fuori da kris/, percorsi con .. o assoluti, link simbolici e
     * pacchetti troppo grandi.
     */
    public static function extract(string $zipFile, string $destDir): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new UpdateException("Su questo hosting manca l'estensione PHP zip: senza, non si possono aprire i pacchetti. Chiedi all'hosting di attivarla.");
        }
        if (!is_file($zipFile) || filesize($zipFile) > self::MAX_ZIP_BYTES) {
            throw new UpdateException('Il pacchetto manca o è troppo grande.');
        }
        if (file_exists($destDir)) {
            throw new UpdateException('La cartella di preparazione esiste già: riprova.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
            throw new UpdateException('Il file caricato non è un pacchetto zip valido.');
        }
        try {
            if ($zip->numFiles === 0 || $zip->numFiles > self::MAX_ENTRIES) {
                throw new UpdateException('Il pacchetto è vuoto o contiene troppi file.');
            }

            $entries = [];
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = (string) ($stat['name'] ?? '');
                self::assertSafeEntry($name);

                if ($zip->getExternalAttributesIndex($i, $opsys, $attr)
                    && $opsys === ZipArchive::OPSYS_UNIX
                    && (($attr >> 16) & 0170000) === 0120000) {
                    throw new UpdateException('Il pacchetto contiene un collegamento simbolico: rifiutato.');
                }
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::MAX_UNPACKED_BYTES) {
                    throw new UpdateException('Il pacchetto, una volta aperto, sarebbe troppo grande.');
                }
                $entries[$i] = $name;
            }

            if (!@mkdir($destDir, 0775, true)) {
                throw new UpdateException('Non riesco a creare la cartella di preparazione in data/updates/.');
            }
            foreach ($entries as $i => $name) {
                $target = $destDir . '/' . $name;
                if (str_ends_with($name, '/')) {
                    if (!is_dir($target) && !@mkdir($target, 0775, true)) {
                        throw new UpdateException('Non riesco a estrarre il pacchetto.');
                    }
                    continue;
                }
                $dir = dirname($target);
                if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                    throw new UpdateException('Non riesco a estrarre il pacchetto.');
                }
                $content = $zip->getFromIndex($i);
                if ($content === false || @file_put_contents($target, $content) === false) {
                    throw new UpdateException('Non riesco a estrarre il pacchetto.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * Verifica firma e contenuto della cartella kris/ estratta.
     *
     * @return array il contenuto di RELEASE.json
     */
    public static function verify(string $krisDir, array $publicKeys): array
    {
        $base = dirname($krisDir);
        $manifestRaw = @file_get_contents($base . '/' . self::MANIFEST);
        $signature = @file_get_contents($base . '/' . self::SIGNATURE);
        if ($manifestRaw === false || $signature === false) {
            throw new UpdateException('Il pacchetto non è firmato: manca il manifest o la firma.');
        }
        if (!Signature::verify($manifestRaw, $signature, $publicKeys)) {
            throw new UpdateException('La firma del pacchetto non è valida: il pacchetto non viene da Kris o è stato modificato.');
        }

        $manifest = json_decode($manifestRaw, true);
        if (!is_array($manifest) || !is_array($manifest['files'] ?? null) || !is_string($manifest['version'] ?? null)) {
            throw new UpdateException('Il manifest del pacchetto è illeggibile.');
        }

        $found = [];
        foreach (self::listFiles($krisDir) as $relative) {
            $name = 'kris/' . $relative;
            if ($name === self::MANIFEST || $name === self::SIGNATURE) {
                continue;
            }
            $found[$name] = true;
            $expected = $manifest['files'][$name] ?? null;
            if (!is_string($expected)) {
                throw new UpdateException("Il pacchetto contiene un file non previsto: {$name}.");
            }
            if (!hash_equals($expected, hash_file('sha256', $krisDir . '/' . $relative))) {
                throw new UpdateException("Un file del pacchetto è stato modificato: {$name}.");
            }
        }
        foreach (array_keys($manifest['files']) as $name) {
            if (!isset($found[$name])) {
                throw new UpdateException("Nel pacchetto manca un file: {$name}.");
            }
        }

        $release = json_decode((string) @file_get_contents($krisDir . '/RELEASE.json'), true);
        $version = trim((string) @file_get_contents($krisDir . '/VERSION'));
        if (!is_array($release) || !is_string($release['version'] ?? null)) {
            throw new UpdateException('Il pacchetto non dichiara la propria versione.');
        }
        if ($release['version'] !== $manifest['version'] || $release['version'] !== $version) {
            throw new UpdateException('Il pacchetto dichiara versioni diverse in punti diversi: rifiutato.');
        }
        return $release;
    }

    /** Percorsi relativi di tutti i file sotto $dir, con separatore /. */
    public static function listFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }
        sort($files);
        return $files;
    }

    private static function assertSafeEntry(string $name): void
    {
        $bad = $name === ''
            || !str_starts_with($name, 'kris/')
            || str_contains($name, '\\')
            || str_contains($name, ':')
            || str_contains($name, "\0");
        if (!$bad) {
            foreach (explode('/', rtrim($name, '/')) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    $bad = true;
                    break;
                }
            }
        }
        if ($bad) {
            throw new UpdateException('Il pacchetto contiene percorsi non ammessi: rifiutato.');
        }
    }
}

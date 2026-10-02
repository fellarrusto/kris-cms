<?php
declare(strict_types=1);

namespace Kris\Update;

/**
 * File di una riga nella root del sito che puntano al framework.
 *
 * Un aggiornamento sostituisce solo kris/: se una versione nuova introduce
 * un punto d'ingresso, il suo stub va creato qui. Gli stub esistenti non
 * vengono mai riscritti (il sito potrebbe averli personalizzati), e ogni
 * stub deve rispondere 404, non andare in errore, se il framework torna a
 * una versione che non ha il file di destinazione.
 */
final class Stubs
{
    /** Percorso dello stub nella root => file del framework a cui punta. */
    private const STUBS = [
        'sitemap.php' => 'kris/sitemap.php',
    ];

    /** Crea gli stub mancanti; restituisce quelli creati. Non solleva errori. */
    public static function ensure(string $root): array
    {
        $created = [];
        foreach (self::STUBS as $stub => $target) {
            $file = rtrim($root, '/\\') . '/' . $stub;
            if (file_exists($file)) {
                continue;
            }
            $php = "<?php\n// Servito dal framework in kris/: questo file resta fisso.\n"
                . "if (is_file(__DIR__ . '/{$target}')) {\n    require __DIR__ . '/{$target}';\n} else {\n    http_response_code(404);\n}\n";
            if (@file_put_contents($file, $php) !== false) {
                $created[] = $stub;
            }
        }
        return $created;
    }
}

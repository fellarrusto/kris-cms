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
        'kris-agent.php' => 'kris/agent.php',
    ];

    /**
     * Regola Apache: un indirizzo che non esiste mostra la 404 del sito, con
     * stato 404 (non un redirect), e un file mancante di assets/ o con
     * l'estensione di un file risponde con la 404 semplice di Apache. Relativa,
     * quindi vale anche in una sottocartella. Su Nginx serve una regola equivalente.
     */
    public const HTACCESS = <<<'HTACCESS'
# Kris: gli indirizzi che non esistono mostrano la 404 del sito (stato 404, nessun redirect)
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^assets/ - [R=404,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule !\.(?:css|js|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|mp4|webm|pdf|txt|xml|json)$ index.php?page=404 [L]
</IfModule>

HTACCESS;

    /** Crea gli stub mancanti; restituisce quelli creati. Non solleva errori. */
    public static function ensure(string $root): array
    {
        $created = [];
        // Il .htaccess della root si crea solo se non c'e: quello del sito puo avere altre regole e non si tocca
        $htaccess = rtrim($root, '/\\') . '/.htaccess';
        if (!file_exists($htaccess) && @file_put_contents($htaccess, self::HTACCESS) !== false) {
            $created[] = '.htaccess';
        }
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

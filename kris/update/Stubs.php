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
        // Dalla 2.0 l'editor sta in /kris-admin/: /editor/ resta un nome libero per le pagine del sito
        'kris-admin/index.php' => 'kris/editor/index.php',
        'kris-admin/upload.php' => 'kris/editor/upload.php',
        'kris-admin/update.php' => 'kris/editor/update.php',
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
RewriteRule ^robots\.txt$ sitemap.php?robots=1 [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^assets/ - [R=404,L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule !\.(?:css|js|map|png|jpe?g|gif|webp|avif|svg|ico|woff2?|ttf|mp4|webm|pdf|txt|xml|json)$ index.php [L,QSA]
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
        } elseif (is_file($htaccess)) {
            // La regola di Kris fino alla 1.9 mandava tutto alla 404: ora passa il percorso a index.php (indirizzi leggibili)
            $rules = (string) file_get_contents($htaccess);
            $updated = str_replace(' index.php?page=404 [L]', ' index.php [L,QSA]', $rules);
            // robots.txt generato da Kris (dalla 2.0), solo nei .htaccess che hanno già la regola di Kris
            if (str_contains($updated, 'index.php [L,QSA]') && !str_contains($updated, 'sitemap.php?robots=1')) {
                $updated = str_replace("RewriteEngine On\n", "RewriteEngine On\nRewriteCond %{REQUEST_FILENAME} !-f\nRewriteRule ^robots\\.txt$ sitemap.php?robots=1 [L]\n", $updated);
            }
            if ($updated !== $rules && @file_put_contents($htaccess, $updated) !== false) $created[] = '.htaccess';
        }
        foreach (self::STUBS as $stub => $target) {
            $file = rtrim($root, '/\\') . '/' . $stub;
            if (file_exists($file)) {
                continue;
            }
            // Uno stub in una sottocartella risale alla root del sito
            $target = str_repeat('../', substr_count($stub, '/')) . $target;
            $php = "<?php\n// Servito dal framework in kris/: questo file resta fisso.\n"
                . "if (is_file(__DIR__ . '/{$target}')) {\n    require __DIR__ . '/{$target}';\n} else {\n    http_response_code(404);\n}\n";
            if (!is_dir(dirname($file))) @mkdir(dirname($file), 0775, true);
            if (@file_put_contents($file, $php) !== false) {
                $created[] = $stub;
            }
        }
        // Gli stub di /editor/ della 1.x diventano un rimando a /kris-admin/: l'aggiornamento parte da lì
        // e il browser ricarica quella pagina. Solo se sono ancora gli stub di Kris.
        foreach (['index.php', 'upload.php', 'update.php'] as $old) {
            $file = rtrim($root, '/\\') . '/editor/' . $old;
            if (is_file($file) && str_contains((string) file_get_contents($file), "'/../kris/editor/{$old}'")) {
                $redirect = "<?php\n// L'editor si è spostato in /kris-admin/ con Kris 2.0: questo file rimanda lì.\n"
                    . "header('Location: ../kris-admin/" . ($old === 'index.php' ? '' : $old) . "' . (\$_SERVER['QUERY_STRING'] ?? '' ? '?' . \$_SERVER['QUERY_STRING'] : ''), true, 301);\n";
                if (@file_put_contents($file, $redirect) !== false) $created[] = "editor/{$old}";
            }
        }
        return $created;
    }
}

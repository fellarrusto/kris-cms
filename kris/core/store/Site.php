<?php
declare(strict_types=1);

namespace Kris\Store;

use Kris\Site\Paths;

/**
 * I dati del sito nel formato 2 (docs/2.0/specifica.md, 2): schema,
 * contenuti e impostazioni, letti dall'archivio una volta per richiesta.
 *
 * Un sito ancora nel formato 1 (prima della migrazione 0009) si legge
 * convertito al volo con la migrazione stessa: il rendering lavora sempre
 * sul formato 2. $preview: con la sovrapposizione dello staging.
 */
final class Site
{
    /** @var array<string, array{schema: array, content: array, settings: array}> */
    private static array $loaded = [];

    /** @return array{schema: array, content: array, settings: array} */
    public static function load(?string $root = null, bool $preview = false): array
    {
        $root = rtrim($root ?? KRIS_ROOT, '/\\');
        $preview = $preview && $root === rtrim(KRIS_ROOT, '/\\');
        $key = $root . '|' . ($preview ? (string) Paths::overlay() : '');
        return self::$loaded[$key] ??= self::read(Stores::site($root, $preview), $root, $preview);
    }

    /**
     * I dati come sono nell'archivio in questo momento, senza la copia della
     * richiesta: per chi li riscrive sotto lock (pubblicazione dello staging).
     *
     * @return array{schema: array, content: array, settings: array}
     */
    public static function current(string $root): array
    {
        return self::read(Stores::site(rtrim($root, '/\\')), rtrim($root, '/\\'), false);
    }

    /** Dopo una scrittura: la prossima lettura rilegge l'archivio. */
    public static function forget(): void
    {
        self::$loaded = [];
    }

    /** Lingue attive, la principale per prima. */
    public static function languages(?string $root = null): array
    {
        $langs = self::load($root)['settings']['languages'] ?? null;
        return is_array($langs) && $langs ? array_values(array_map('strval', $langs)) : ['it', 'en'];
    }

    /** Campi di un tipo per nome; [] se il tipo non esiste. */
    public static function fields(array $schema, string $type): array
    {
        $fields = $schema['types'][$type]['fields'] ?? [];
        return is_array($fields) ? array_column(array_filter($fields, 'is_array'), null, 'name') : [];
    }

    /**
     * Un vecchio riferimento a un elemento di un elenco promosso a tipo
     * (key=homepage&id=0&path=posts/3) diventa il contenuto del tipo nuovo
     * (key=posts&id=3); gli altri restano come sono.
     *
     * @return array{0: string, 1: int, 2: list<string>}
     */
    public static function normalize(array $schema, string $key, int $id, array $path): array
    {
        $path = array_values(array_map('strval', $path));
        if (count($path) < 2 || !ctype_digit($path[1])) return [$key, $id, $path];
        foreach (is_array($schema['promoted'] ?? null) ? $schema['promoted'] : [] as $p) {
            if (($p['from'] ?? null) === $key && (int) ($p['id'] ?? -1) === $id && ($p['list'] ?? null) === $path[0]) {
                return [(string) $p['to'], (int) $path[1], array_slice($path, 2)];
            }
        }
        return [$key, $id, $path];
    }

    /** @return array{schema: array, content: array, settings: array} */
    private static function read(Store $store, string $root, bool $preview): array
    {
        $schema = $store->read('schema');
        // Uno schema vuoto è quello di una pubblicazione annullata su un sito del formato 1
        if ($schema->rev !== '' && $schema->value !== []) {
            return [
                'schema' => $schema->value,
                'content' => $store->read('content')->value,
                'settings' => $store->read('settings')->value,
            ];
        }
        // Formato 1: la conversione della migrazione, sugli stessi documenti
        $templates = [];
        foreach (glob($root . '/template/*.html') ?: [] as $file) {
            $name = basename($file, '.html');
            $source = $preview ? Paths::template($name) : $file;
            if (is_file($source)) $templates[$name] = (string) file_get_contents($source);
        }
        if ($preview && Paths::overlay() !== null) {
            foreach (glob(Paths::overlay() . '/template/*.html') ?: [] as $file) {
                $templates[basename($file, '.html')] ??= (string) file_get_contents($file);
            }
        }
        $legacy = fn(string $doc): ?array => ($snap = $store->read($doc))->rev === '' ? null : $snap->value;
        $migration = require KRIS_DIR . '/migrations/0009_formato_2.php';
        $converted = ($migration['up'])([
            'k_data' => $legacy('data') ?? [],
            'k_model' => $legacy('model') ?? [],
            'cms_settings' => $legacy('cms_settings'),
            'allowed_pages' => $legacy('pages'),
            'seo' => $legacy('seo'),
            'templates' => $templates,
        ]);
        return ['schema' => $converted['schema'], 'content' => $converted['content'], 'settings' => $converted['settings']];
    }
}

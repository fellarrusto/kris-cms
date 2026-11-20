<?php
declare(strict_types=1);

namespace Kris\Store;

/**
 * L'archivio del sito, scelto da config/store.php (docs/2.0/specifica.md,
 * 1.5). Senza file vale l'adapter JSON. Uno per root e modalità.
 */
final class Stores
{
    /** @var array<string, Store> */
    private static array $stores = [];

    /** L'archivio del sito; $preview per il rendering, che vede l'anteprima dello staging. */
    public static function site(?string $root = null, bool $preview = false): Store
    {
        $root ??= KRIS_ROOT;
        return self::$stores[$root . '|' . ($preview ? 'preview' : 'site')] ??= self::open($root, $preview);
    }

    private static function open(string $root, bool $preview): Store
    {
        $config = is_file("{$root}/config/store.php") ? require "{$root}/config/store.php" : [];
        $config = is_array($config) ? $config : [];
        $driver = (string) ($config['driver'] ?? 'json');
        return match ($driver) {
            'json' => new FileStore($root, $preview),
            'pdo' => new PdoStore($root, $config, $preview),
            default => throw new StoreException("Archivio «{$driver}» non disponibile in questa versione di Kris."),
        };
    }

    /** Il driver scelto da config/store.php: json o pdo. */
    public static function driver(string $root): string
    {
        $config = is_file("{$root}/config/store.php") ? require "{$root}/config/store.php" : [];
        return is_array($config) ? (string) ($config['driver'] ?? 'json') : 'json';
    }

    /** Solo per i test: dimentica gli archivi aperti. */
    public static function reset(): void
    {
        self::$stores = [];
    }
}

<?php
declare(strict_types=1);

namespace Kris\Site;

/**
 * Dove il rendering pubblico legge contenuti, template e pagine del sito.
 *
 * Di norma sono i file del sito sotto KRIS_ROOT. Con una sovrapposizione
 * attiva (l'anteprima dello staging) un file presente nella cartella
 * sovrapposta vince su quello del sito, a parita di percorso relativo:
 * `<overlay>/template/home.html` sostituisce `template/home.html`, e cosi via.
 * I file che la sovrapposizione non ha restano quelli del sito.
 *
 * Solo letture: le scritture dell'editor vanno sempre ai file del sito e non
 * passano da qui. La sovrapposizione la attiva soltanto l'anteprima per un
 * admin; sitemap, editor e visitatori leggono sempre il sito.
 */
final class Paths
{
    private static ?string $overlay = null;
    /** @var array<string, true> file che la sovrapposizione elimina: per l'anteprima non esistono */
    private static array $removed = [];

    /** Cartella da sovrapporre al sito, o null per leggere solo il sito; $removed: percorsi relativi eliminati. */
    public static function useOverlay(?string $dir, array $removed = []): void
    {
        self::$overlay = $dir === null ? null : rtrim($dir, '/\\');
        self::$removed = $dir === null ? [] : array_fill_keys($removed, true);
    }

    public static function overlay(): ?string
    {
        return self::$overlay;
    }

    /** Un file del sito, per esempio 'data/k_data.json' o 'config/allowed_pages.json'. */
    public static function file(string $relative): string
    {
        $relative = ltrim($relative, '/');
        if (self::$overlay !== null && isset(self::$removed[$relative])) {
            // Eliminato nell'anteprima: un percorso che non esiste
            return self::$overlay . '/' . $relative;
        }
        if (self::$overlay !== null && is_file(self::$overlay . '/' . $relative)) {
            return self::$overlay . '/' . $relative;
        }
        return KRIS_ROOT . '/' . $relative;
    }

    /** Un archivio dei contenuti in data/, per nome senza estensione: 'k_data'. */
    public static function data(string $name): string
    {
        return self::file('data/' . $name . '.json');
    }

    /** Un template o frammento in template/, per nome senza estensione. */
    public static function template(string $name): string
    {
        return self::file('template/' . $name . '.html');
    }
}

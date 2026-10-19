<?php
declare(strict_types=1);

namespace Kris\Template;

/**
 * Versione automatica dei fogli di stile e degli script del tema.
 *
 * Dopo una pubblicazione il browser (o il proxy dell'hosting) puo tenere in
 * cache il CSS vecchio con l'HTML nuovo, o il contrario, e la pagina si rompe
 * finche non si forza il ricaricamento. Gli indirizzi assets/css/... e
 * assets/js/... delle pagine ricevono ?v= con la data di modifica del file:
 * quando il file cambia, cambia l'indirizzo. Gli indirizzi che hanno gia una
 * query restano come sono.
 */
final class Assets
{
    public static function version(string $html, string $root): string
    {
        return (string) preg_replace_callback(
            '#\b(href|src)=(["\'])((?:\./)?assets/(?:css|js)/[A-Za-z0-9][A-Za-z0-9_./-]*\.(?:css|js))\2#',
            function (array $m) use ($root): string {
                $relative = (string) preg_replace('#^\./#', '', $m[3]);
                if (str_contains($relative, '..')) return $m[0];
                $time = @filemtime($root . '/' . $relative);
                return $time === false ? $m[0] : $m[1] . '=' . $m[2] . $m[3] . '?v=' . base_convert((string) $time, 10, 36) . $m[2];
            },
            $html
        );
    }
}

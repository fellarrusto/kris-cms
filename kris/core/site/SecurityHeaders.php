<?php
declare(strict_types=1);

namespace Kris\Site;

/**
 * Intestazioni di sicurezza delle pagine pubbliche, di serie su ogni sito.
 * Le manda PHP, non il .htaccess: valgono anche su Nginx e un errore non
 * spegne il sito. Un'intestazione già impostata (da un plugin, dal server
 * via PHP) resta com'è. Si spengono con "security": {"headers": false} in
 * data/settings.json.
 */
final class SecurityHeaders
{
    /** @return array<string, string> nome => valore, per questa richiesta */
    public static function for(array $settings, bool $https): array
    {
        if (($settings['security']['headers'] ?? true) === false) return [];
        $headers = [
            // Un file non si spaccia per un altro tipo (un caricamento non diventa uno script)
            'X-Content-Type-Options' => 'nosniff',
            // Agli altri siti solo il dominio di provenienza, non l'indirizzo completo
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            // Il sito non si apre dentro il riquadro di un altro sito (clickjacking); l'editor e l'anteprima sì
            'X-Frame-Options' => 'SAMEORIGIN',
        ];
        // Solo su https: il browser ricorda per un anno di non usare http
        if ($https) $headers['Strict-Transport-Security'] = 'max-age=31536000';
        return $headers;
    }

    public static function send(array $settings, bool $https): void
    {
        if (headers_sent()) return;
        $sent = [];
        foreach (headers_list() as $line) $sent[strtolower(trim(explode(':', $line, 2)[0]))] = true;
        foreach (self::for($settings, $https) as $name => $value) {
            if (!isset($sent[strtolower($name)])) header("{$name}: {$value}");
        }
    }
}

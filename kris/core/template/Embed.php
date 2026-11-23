<?php
declare(strict_types=1);

namespace Kris\Template;

/**
 * Campo "embed": un contenuto di un altro sito (video, post, mappa) dentro
 * la pagina, come iframe, solo da domini ammessi. Chi scrive incolla
 * l'indirizzo della pagina (youtube.com/watch?v=…, instagram.com/p/…) o il
 * codice di incorporamento; si salva l'indirizzo dell'iframe, ricavato e
 * controllato qui. Il template scrive {{campo}} dove va l'iframe.
 *
 * I domini ammessi stanno in settings.embed.domains (editor › Impostazioni,
 * operazione set_embed_domains); senza, quelli di DEFAULT_DOMAINS.
 */
final class Embed
{
    public const DEFAULT_DOMAINS = ['youtube.com', 'youtube-nocookie.com', 'vimeo.com', 'instagram.com', 'tiktok.com', 'google.com', 'spotify.com'];

    /** @return list<string> i domini ammessi dalle impostazioni del sito */
    public static function domains(array $settings): array
    {
        $list = $settings['embed']['domains'] ?? null;
        return is_array($list) ? array_values(array_filter(array_map('strval', $list), fn($d) => $d !== '')) : self::DEFAULT_DOMAINS;
    }

    /** Un nome di dominio valido, in minuscolo, senza schema né www.; null se non lo è. */
    public static function domain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $domain = (string) preg_replace('#^(https?://)?(www\.)?#', '', $domain);
        $domain = rtrim($domain, '/');
        return preg_match('/^(?=.{3,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain) === 1 ? $domain : null;
    }

    /**
     * L'indirizzo dell'iframe da quello che ha incollato chi scrive: un
     * indirizzo o un codice di incorporamento (<iframe src="…">). "" se vuoto.
     *
     * @throws \InvalidArgumentException indirizzo non https o dominio non ammesso
     */
    public static function normalize(string $input, array $domains): string
    {
        $input = trim($input);
        if ($input === '') return '';
        // Un codice di incorporamento: conta solo l'indirizzo dell'iframe (Instagram e TikTok danno un blockquote con il link)
        if (str_contains($input, '<')) {
            if (preg_match('#<iframe\b[^>]*\bsrc=(["\'])(.*?)\1#is', $input, $m) === 1) {
                $input = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('#\b(?:data-instgrm-permalink|cite)=(["\'])(https://[^"\']+)\1#i', $input, $m) === 1) {
                $input = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } else {
                throw new \InvalidArgumentException('Nel codice incollato non trovo l\'indirizzo del contenuto: incolla l\'indirizzo della pagina (per esempio https://www.youtube.com/watch?v=…).');
            }
        }
        if (str_starts_with($input, '//')) $input = 'https:' . $input;
        $url = self::known($input) ?? $input;
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '' || preg_match('/[\s"\'<>]/', $url) === 1) {
            throw new \InvalidArgumentException('Serve un indirizzo https, per esempio https://www.youtube.com/watch?v=….');
        }
        if (!self::allowed($host, $domains)) {
            throw new \InvalidArgumentException("{$host} non è fra i siti ammessi per i contenuti incorporati (" . implode(', ', $domains) . '): aggiungilo in Impostazioni › Contenuti incorporati.');
        }
        return $url;
    }

    public static function allowed(string $host, array $domains): bool
    {
        $host = strtolower($host);
        foreach ($domains as $domain) {
            $domain = strtolower((string) $domain);
            if ($domain !== '' && ($host === $domain || str_ends_with($host, '.' . $domain))) return true;
        }
        return false;
    }

    /** L'iframe di un valore salvato; "" se vuoto o se il dominio non è più ammesso. */
    public static function html(string $url, array $domains, string $title = ''): string
    {
        $url = trim($url);
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($url === '' || !str_starts_with($url, 'https://') || !self::allowed($host, $domains) || preg_match('/[\s"\'<>]/', $url) === 1) return '';
        $esc = fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        // Proporzioni di partenza (il tema le cambia con le classi kris-embed-*): video larghi, post verticali, lettori audio bassi
        [$ratio, $style] = match (true) {
            str_contains($host, 'instagram.com'), str_contains($host, 'tiktok.com') => ['kris-embed-tall', 'max-width:540px;aspect-ratio:4/5'],
            str_contains($host, 'spotify.com') => ['kris-embed-audio', 'height:152px'],
            default => ['kris-embed-wide', 'aspect-ratio:16/9'],
        };
        return '<iframe class="kris-embed ' . $ratio . '" style="display:block;width:100%;border:0;' . $style . '" src="' . $esc($url) . '" title="' . $esc($title !== '' ? $title : $host) . '" loading="lazy"'
            . ' referrerpolicy="strict-origin-when-cross-origin" allow="autoplay; encrypted-media; fullscreen; picture-in-picture; clipboard-write" allowfullscreen></iframe>';
    }

    /** Indirizzo dell'iframe per gli indirizzi di pagina dei servizi più comuni. */
    private static function known(string $url): ?string
    {
        $patterns = [
            '#^https://(?:www\.|m\.)?youtube\.com/watch\?(?:.*&)?v=([\w-]{6,})#' => 'https://www.youtube-nocookie.com/embed/$1',
            '#^https://youtu\.be/([\w-]{6,})#' => 'https://www.youtube-nocookie.com/embed/$1',
            '#^https://(?:www\.)?youtube\.com/shorts/([\w-]{6,})#' => 'https://www.youtube-nocookie.com/embed/$1',
            '#^https://(?:www\.)?vimeo\.com/(\d+)#' => 'https://player.vimeo.com/video/$1',
            '#^https://(?:www\.)?instagram\.com/(p|reel|tv)/([\w-]+)/?(?:\?.*)?$#' => 'https://www.instagram.com/$1/$2/embed',
            '#^https://(?:www\.)?tiktok\.com/@[\w.-]+/video/(\d+)#' => 'https://www.tiktok.com/embed/v2/$1',
            '#^https://open\.spotify\.com/(track|album|playlist|episode|show)/(\w+)#' => 'https://open.spotify.com/embed/$1/$2',
        ];
        foreach ($patterns as $pattern => $to) {
            if (preg_match($pattern, $url) === 1) return (string) preg_replace(substr($pattern, 0, -1) . '.*$#', $to, $url);
        }
        return null;
    }
}

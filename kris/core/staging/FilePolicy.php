<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Quali file del sito si possono modificare passando dallo staging, e con
 * quale contenuto. Un elenco chiuso: tutto cio che non e previsto qui viene
 * rifiutato, in particolare il framework (kris/), gli stub, ogni file PHP,
 * .htaccess, config/ e data/.
 */
final class FilePolicy
{
    private const EDITABLE = [
        '#^template/[a-z0-9][a-z0-9_-]*\.html$#',
        '#^assets/css/[a-z0-9][a-z0-9_-]*\.css$#',
    ];

    public const MAX_BYTES = 512 * 1024;

    /** @throws StagingException percorso fuori dall'elenco */
    public static function assertEditable(string $relative): void
    {
        foreach (self::EDITABLE as $pattern) {
            if (preg_match($pattern, $relative) === 1) {
                return;
            }
        }
        throw new StagingException(
            "Il file '{$relative}' non si può modificare: sono ammessi solo template/<nome>.html e assets/css/<nome>.css, "
            . 'con nomi in minuscolo, cifre, - e _.'
        );
    }

    /**
     * Quello che una pubblicazione puo toccare e quindi un annullamento
     * ripristinare: i file del tema, contenuti e schema, pagine e sitemap,
     * le immagini caricate. Difende da una copia di sicurezza manomessa.
     *
     * @throws StagingException
     */
    public static function assertRestorable(string $relative): void
    {
        $fixed = ['data/k_data.json', 'data/k_model.json', 'config/allowed_pages.json', 'config/seo.json'];
        if (in_array($relative, $fixed, true) || preg_match('#^assets/uploads/[A-Za-z0-9][A-Za-z0-9._-]*$#', $relative) === 1) {
            return;
        }
        self::assertEditable($relative);
    }

    /** @throws StagingException contenuto che non deve finire nel sito */
    public static function assertContent(string $relative, string $content): void
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new StagingException(sprintf('Il file supera il limite di %d KB.', self::MAX_BYTES / 1024));
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            throw new StagingException('Il contenuto non è testo UTF-8 valido.');
        }
        // I template si leggono e non si eseguono, ma codice PHP in un file del tema non ha mai senso
        if (preg_match('/<\?(php|=)?/i', $content) === 1) {
            throw new StagingException('Il contenuto contiene un tag PHP: non è ammesso nei file del tema.');
        }
    }

    /**
     * Elementi da far notare all'admin prima della promozione: script, gestori
     * di eventi, URL javascript: e domini esterni. Non bloccano nulla.
     *
     * @return string[]
     */
    public static function sensitive(string $content): array
    {
        $found = [];
        if (preg_match_all('/<script\b[^>]*>/i', $content, $m)) {
            foreach ($m[0] as $tag) $found[] = 'script: ' . $tag;
        }
        if (preg_match_all('/\son[a-z]+\s*=/i', $content, $m)) {
            foreach ($m[0] as $attr) $found[] = 'gestore di eventi: ' . trim(rtrim($attr, '= '));
        }
        if (preg_match('/javascript\s*:/i', $content)) {
            $found[] = 'URL javascript:';
        }
        if (preg_match_all('#(?:https?:)?//([a-z0-9.-]+\.[a-z]{2,})#i', $content, $m)) {
            foreach ($m[1] as $host) $found[] = 'dominio esterno: ' . strtolower($host);
        }
        return array_values(array_unique($found));
    }
}

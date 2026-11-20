<?php
declare(strict_types=1);

namespace Kris\Store;

/**
 * Archivio dei dati del sito, a documenti interi (docs/2.0/specifica.md, 1.3).
 * Nessun codice fuori da Kris\Store apre i file dei dati o il database.
 *
 * Documenti del formato 2: schema, content, settings. Del formato 1, finché
 * la migrazione 0009 non li converte: data (contenuti), model (schema), cms_settings
 * (impostazioni dell'editor), pages (pagine pubbliche), seo (sitemap e head).
 */
interface Store
{
    public const DOCUMENTS = ['schema', 'content', 'settings', 'data', 'model', 'cms_settings', 'pages', 'seo'];

    /** Il documento e la sua revisione. Un documento che non esiste è [] con revisione ''. */
    public function read(string $doc): Snapshot;

    /**
     * Sostituisce il documento e restituisce la revisione nuova. Con
     * $expectedRev riesce solo se nessuno ha scritto dopo quella revisione.
     *
     * @throws ConflictException|StoreException
     */
    public function write(string $doc, array $value, ?string $expectedRev = null): string;

    /**
     * Più documenti tutti o nessuno, sotto lock. La callback riceve i
     * documenti letti e restituisce quelli da scrivere (solo i cambiati).
     *
     * @param list<string> $docs
     * @param callable(array<string, array>): ?array<string, array> $change
     * @throws StoreException
     */
    public function transaction(array $docs, callable $change): void;

    /**
     * Toglie dei documenti dall'archivio: i documenti del formato 1, dopo la
     * conversione al formato 2 (Kris\Update\Migrator).
     *
     * @param list<string> $docs
     */
    public function remove(array $docs): void;

    /** @return array<string, array> i documenti che esistono */
    public function export(): array;

    /**
     * Sostituisce l'intero archivio: i documenti che $documents non ha
     * vengono tolti (un ripristino non lascia quelli creati dopo).
     *
     * @param array<string, array> $documents
     */
    public function import(array $documents): void;
}

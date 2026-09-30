<?php
declare(strict_types=1);

namespace Kris\Entity;

/**
 * Unico punto di lettura e scrittura dei file JSON di contenuto.
 *
 * Garanzie:
 *  - un file illeggibile o malformato solleva StorageException, non diventa []
 *  - la scrittura e atomica: si scrive un temporaneo e lo si rinomina, quindi
 *    un'interruzione non lascia mai un file troncato
 *  - prima di ogni scrittura viene conservata una copia dello stato precedente
 *  - ogni errore (encode, write, rename) viene rilevato e sollevato
 *
 * Sta nel namespace Kris\Entity di proposito: usa la mappatura PSR-4 gia
 * presente in composer.json, cosi non serve rigenerare l'autoloader sul server.
 */
final class JsonStore
{
    /** Numero di copie di sicurezza conservate per ciascun file. */
    private const KEEP_BACKUPS = 15;

    public function __construct(private string $file)
    {
    }

    public function exists(): bool
    {
        return is_file($this->file);
    }

    public function path(): string
    {
        return $this->file;
    }

    /**
     * Legge il file.
     *
     * @param array|null $defaultIfMissing valore da usare se il file non esiste.
     *                                     Se null, l'assenza del file e un errore.
     * @throws StorageException se il file esiste ma non e leggibile o valido
     */
    public function read(?array $defaultIfMissing = null): array
    {
        if (!is_file($this->file)) {
            if ($defaultIfMissing !== null) {
                return $defaultIfMissing;
            }
            throw new StorageException("Archivio non trovato: {$this->file}");
        }

        $raw = @file_get_contents($this->file);
        if ($raw === false) {
            throw new StorageException("Impossibile leggere l'archivio: {$this->file}");
        }

        // Un file vuoto non e un archivio valido: se lo trattassimo come []
        // la prima scrittura successiva cancellerebbe tutto.
        if (trim($raw) === '') {
            throw new StorageException("Archivio vuoto o troncato: {$this->file}");
        }

        $data = json_decode($raw, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new StorageException(sprintf(
                "Archivio JSON non valido (%s): %s",
                json_last_error_msg(),
                $this->file
            ));
        }
        if (!is_array($data)) {
            throw new StorageException("Archivio JSON con struttura inattesa: {$this->file}");
        }

        return $data;
    }

    /**
     * Scrive il file in modo atomico, con copia di sicurezza dello stato precedente.
     *
     * @throws StorageException se una qualsiasi fase fallisce
     */
    public function write(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new StorageException('Impossibile serializzare i dati: ' . json_last_error_msg());
        }

        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new StorageException("Cartella dati non creabile: {$dir}");
        }
        if (!is_writable($dir)) {
            throw new StorageException("Cartella dati non scrivibile: {$dir}");
        }

        $this->backup();

        $tmp = $this->file . '.' . bin2hex(random_bytes(4)) . '.tmp';
        $written = @file_put_contents($tmp, $json, LOCK_EX);
        if ($written === false || $written !== strlen($json)) {
            @unlink($tmp);
            throw new StorageException("Scrittura incompleta dell'archivio: {$this->file}");
        }

        // rename() e atomico sullo stesso volume: chi legge vede il file
        // vecchio o quello nuovo, mai una via di mezzo.
        if (!@rename($tmp, $this->file)) {
            @unlink($tmp);
            throw new StorageException("Impossibile sostituire l'archivio: {$this->file}");
        }
    }

    /** Copia lo stato attuale in data/backups/ e tiene le ultime KEEP_BACKUPS. */
    private function backup(): void
    {
        if (!is_file($this->file) || filesize($this->file) === 0) {
            return;
        }

        $dir = dirname($this->file) . '/backups';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return; // il backup non deve impedire il salvataggio
        }

        $name = pathinfo($this->file, PATHINFO_FILENAME);
        @copy($this->file, sprintf('%s/%s-%s.json', $dir, $name, date('Ymd-His')));

        $old = glob($dir . '/' . $name . '-*.json') ?: [];
        if (count($old) > self::KEEP_BACKUPS) {
            sort($old);
            foreach (array_slice($old, 0, count($old) - self::KEEP_BACKUPS) as $file) {
                @unlink($file);
            }
        }
    }
}

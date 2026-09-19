<?php
declare(strict_types=1);

namespace Kris\Entity;

/**
 * Unico punto di ingresso per i file caricati dal pannello.
 *
 * Prima esistevano due upload con politiche diverse (editor/upload.php e il
 * ramo $_FILES dentro editor/index.php): nessuno dei due controllava
 * l'estensione, quindi un .php finiva in assets/uploads/ ed era eseguibile.
 */
final class MediaStore
{
    /** Estensioni ammesse. Gli SVG restano fuori: possono contenere script. */
    private const ALLOWED = [
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'pdf'  => 'application/pdf',
    ];

    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    public function __construct(private string $uploadDir, private string $publicPrefix)
    {
    }

    public static function allowedExtensions(): array
    {
        return array_keys(self::ALLOWED);
    }

    public static function maxBytes(): int
    {
        return self::MAX_BYTES;
    }

    /**
     * Valida e salva un file caricato.
     *
     * @param array $file una voce di $_FILES
     * @return string URL pubblico del file salvato
     * @throws StorageException con un messaggio mostrabile all'utente
     */
    public function store(array $file): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            throw new StorageException($this->describeUploadError((int) $error));
        }
        if (!is_uploaded_file($file['tmp_name'] ?? '')) {
            throw new StorageException('Caricamento non valido.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new StorageException('Il file e vuoto.');
        }
        if ($size > self::MAX_BYTES) {
            throw new StorageException(sprintf(
                'Il file supera il limite di %d MB.',
                (int) (self::MAX_BYTES / 1024 / 1024)
            ));
        }

        $original = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new StorageException(sprintf(
                'Formato non ammesso. Sono accettati: %s.',
                implode(', ', self::allowedExtensions())
            ));
        }

        // Il contenuto reale deve corrispondere all'estensione dichiarata,
        // altrimenti basta rinominare uno script in .png per farlo entrare.
        if (!$this->contentMatches($file['tmp_name'], $ext)) {
            throw new StorageException('Il contenuto del file non corrisponde alla sua estensione.');
        }

        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw new StorageException('Cartella di destinazione non disponibile.');
        }

        // Nome generato: niente estensioni doppie, niente sovrascritture,
        // niente caratteri decisi da chi carica.
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = strtolower(preg_replace('/[^a-zA-Z0-9-_]/', '-', $base) ?? '');
        $base = trim(preg_replace('/-+/', '-', $base) ?? '', '-');
        if ($base === '') $base = 'file';
        $name = sprintf('%s-%s.%s', substr($base, 0, 60), bin2hex(random_bytes(4)), $ext);

        if (!@move_uploaded_file($file['tmp_name'], $this->uploadDir . $name)) {
            throw new StorageException('Non siamo riusciti a salvare il file.');
        }

        return $this->publicPrefix . $name;
    }

    /**
     * Verifica che il contenuto sia davvero del tipo dichiarato.
     *
     * Non si affida alla sola estensione fileinfo: su parecchi hosting (e su
     * questa macchina) non e installata, e un controllo che si disattiva da
     * solo non e un controllo. getimagesize() fa parte del PHP di base.
     */
    private function contentMatches(string $path, string $ext): bool
    {
        if ($ext === 'pdf') {
            return str_starts_with((string) @file_get_contents($path, false, null, 0, 5), '%PDF-');
        }

        $expected = match ($ext) {
            'jpg', 'jpeg' => [IMAGETYPE_JPEG],
            'png'         => [IMAGETYPE_PNG],
            'gif'         => [IMAGETYPE_GIF],
            'webp'        => [IMAGETYPE_WEBP],
            'avif'        => defined('IMAGETYPE_AVIF') ? [IMAGETYPE_AVIF] : [],
            default       => [],
        };

        $info = @getimagesize($path);
        if (is_array($info) && isset($info[2]) && in_array($info[2], $expected, true)) {
            return true;
        }

        // AVIF non e riconosciuto da getimagesize() su PHP piu vecchi:
        // in quel caso si controlla la firma del contenitore.
        if ($ext === 'avif') {
            $head = (string) @file_get_contents($path, false, null, 0, 32);
            return str_contains($head, 'ftypavif') || str_contains($head, 'ftypavis');
        }

        // Se fileinfo c'e, si usa come seconda opinione.
        if (function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_file($finfo, $path);
                finfo_close($finfo);
                return is_string($mime) && $mime === self::ALLOWED[$ext];
            }
        }

        return false;
    }

    private function describeUploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Il file e troppo grande.',
            UPLOAD_ERR_PARTIAL   => 'Il caricamento si e interrotto: riprova.',
            UPLOAD_ERR_NO_FILE   => 'Nessun file selezionato.',
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => 'Il server non e riuscito a scrivere il file.',
            UPLOAD_ERR_EXTENSION => 'Caricamento bloccato dal server.',
            default              => 'Caricamento non riuscito.',
        };
    }
}

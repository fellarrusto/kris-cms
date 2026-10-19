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

    /** Larghezza massima predefinita delle foto, in pixel. */
    public const DEFAULT_MAX_WIDTH = 2560;

    /** @var array{prefix: string, max_width: int, webp: bool} */
    private array $options;

    /**
     * $options: prefisso dei nomi, larghezza massima delle foto (0 = nessuna),
     * conversione in WebP. Senza, quelle del sito (cms_settings.json, "uploads").
     */
    public function __construct(private string $uploadDir, private string $publicPrefix, ?array $options = null)
    {
        $this->options = self::normalizeOptions($options ?? (defined('KRIS_ROOT') ? self::siteOptions(KRIS_ROOT) : []));
    }

    /** Le opzioni di caricamento di un sito, da data/cms_settings.json. */
    public static function siteOptions(string $root): array
    {
        $file = $root . '/data/cms_settings.json';
        $settings = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        return is_array($settings['uploads'] ?? null) ? $settings['uploads'] : [];
    }

    /** @return array{prefix: string, max_width: int, webp: bool} */
    public static function normalizeOptions(array $options): array
    {
        return [
            'prefix' => self::slug(is_string($options['prefix'] ?? null) ? $options['prefix'] : ''),
            'max_width' => max(0, (int) ($options['max_width'] ?? self::DEFAULT_MAX_WIDTH)),
            'webp' => ($options['webp'] ?? false) === true,
        ];
    }

    /** Vero se questo PHP sa ridimensionare e ripulire le foto (estensione GD). */
    public static function canProcessImages(): bool
    {
        return function_exists('imagecreatefromjpeg') && function_exists('imagejpeg');
    }

    /** Vero se questo PHP sa scrivere WebP. */
    public static function canWriteWebp(): bool
    {
        return function_exists('imagewebp') && function_exists('imagecreatefromjpeg');
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

        $name = $this->checkedName($file['tmp_name'], (string) ($file['name'] ?? ''), (int) ($file['size'] ?? 0));
        if (!@move_uploaded_file($file['tmp_name'], $this->uploadDir . $name)) {
            throw new StorageException('Non siamo riusciti a salvare il file.');
        }

        return $this->publicPrefix . $this->processImage($name);
    }

    /**
     * Valida e salva un file che non arriva da un upload HTTP, per esempio
     * un'immagine caricata dall'agente nello staging. Stessi controlli di store().
     *
     * @return string URL pubblico che il file avra
     * @throws StorageException con un messaggio mostrabile all'utente
     */
    public function storeLocal(string $path, string $originalName): string
    {
        if (!is_file($path)) {
            throw new StorageException('Il file da caricare non esiste.');
        }
        $name = $this->checkedName($path, $originalName, (int) filesize($path));
        if (!@copy($path, $this->uploadDir . $name)) {
            throw new StorageException('Non siamo riusciti a salvare il file.');
        }

        return $this->publicPrefix . $this->processImage($name);
    }

    /**
     * Controlli comuni (dimensione, estensione, contenuto reale) e nome con
     * cui salvare il file. Prepara anche la cartella di destinazione.
     *
     * @throws StorageException
     */
    private function checkedName(string $path, string $original, int $size): string
    {
        if ($size <= 0) {
            throw new StorageException('Il file e vuoto.');
        }
        if ($size > self::MAX_BYTES) {
            throw new StorageException(sprintf(
                'Il file supera il limite di %d MB.',
                (int) (self::MAX_BYTES / 1024 / 1024)
            ));
        }

        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED[$ext])) {
            throw new StorageException(sprintf(
                'Formato non ammesso. Sono accettati: %s.',
                implode(', ', self::allowedExtensions())
            ));
        }

        // Il contenuto reale deve corrispondere all'estensione dichiarata,
        // altrimenti basta rinominare uno script in .png per farlo entrare.
        if (!$this->contentMatches($path, $ext)) {
            throw new StorageException('Il contenuto del file non corrisponde alla sua estensione.');
        }

        if (!is_dir($this->uploadDir) && !@mkdir($this->uploadDir, 0775, true) && !is_dir($this->uploadDir)) {
            throw new StorageException('Cartella di destinazione non disponibile.');
        }

        // Nome generato: niente estensioni doppie, niente sovrascritture,
        // niente caratteri decisi da chi carica.
        // Per la SEO: prefisso del sito e nome del file, ma non quelli dati dalla fotocamera (DSC_1234, IMG_0042)
        $base = self::slug(pathinfo($original, PATHINFO_FILENAME));
        if (self::isCameraName($base)) $base = '';
        $prefix = $this->options['prefix'];
        if ($prefix !== '' && !str_starts_with($base, $prefix)) $base = trim($prefix . '-' . $base, '-');
        if ($base === '') $base = 'file';
        return sprintf('%s-%s.%s', rtrim(substr($base, 0, 60), '-'), bin2hex(random_bytes(4)), $ext);
    }

    public static function slug(string $text): string
    {
        $text = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '-', $text));
        return trim((string) preg_replace('/-+/', '-', $text), '-');
    }

    /** Nomi automatici di fotocamere e telefoni, senza informazioni utili. */
    public static function isCameraName(string $slug): bool
    {
        return preg_match('/^(?:dsc[fn]?|img|dcim|pxl|mvimg|photo|image|foto|screenshot|whatsapp-image|gopr|dji|p|sam)-?\d[\d-]*(?:-(?:wa|edited|copy|copia)\d*)*$/', $slug) === 1
            || preg_match('/^\d{8}-\d{6}(?:-\d+)?$/', $slug) === 1;
    }

    /**
     * Ripulisce una foto appena salvata, se questo PHP ha GD: la raddrizza
     * secondo l'orientamento EXIF, la riduce alla larghezza massima e la
     * ricodifica, togliendo i metadati (posizione GPS compresa); con
     * l'opzione WebP la converte. I JPEG si ricodificano sempre, PNG e WebP
     * solo se vanno ridotti o convertiti; GIF, AVIF e PDF restano come sono.
     * Senza GD, o se la foto non sta nella memoria disponibile, il file resta
     * com'era. Restituisce il nome finale.
     */
    private function processImage(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || !self::canProcessImages()) {
            return $name;
        }
        $path = $this->uploadDir . $name;
        $info = @getimagesize($path);
        if (!is_array($info) || !self::fitsInMemory((int) $info[0], (int) $info[1])) {
            return $name;
        }
        $orientation = in_array($ext, ['jpg', 'jpeg'], true) ? self::jpegOrientation($path) : 1;
        $quarter = in_array($orientation, [5, 6, 7, 8], true);
        $width = $quarter ? (int) $info[1] : (int) $info[0];
        $maxWidth = $this->options['max_width'];
        $resize = $maxWidth > 0 && $width > $maxWidth;
        $toWebp = $this->options['webp'] && $ext !== 'webp' && self::canWriteWebp();
        if (!in_array($ext, ['jpg', 'jpeg'], true) && !$resize && !$toWebp) {
            return $name;
        }

        $image = match ($ext) {
            'png' => function_exists('imagecreatefrompng') ? @imagecreatefrompng($path) : false,
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => @imagecreatefromjpeg($path),
        };
        if ($image === false) return $name;
        $image = self::orient($image, $orientation);
        if ($resize) {
            $height = (int) round(imagesy($image) * $maxWidth / imagesx($image));
            $scaled = imagescale($image, $maxWidth, max(1, $height), IMG_BICUBIC);
            if ($scaled !== false) $image = $scaled;
        }

        $outExt = $toWebp ? 'webp' : $ext;
        $outName = $outExt === $ext ? $name : substr($name, 0, -strlen($ext)) . $outExt;
        $tmp = $this->uploadDir . $outName . '.tmp';
        if (in_array($outExt, ['png', 'webp'], true) || $ext === 'png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $saved = match ($outExt) {
            'webp' => @imagewebp($image, $tmp, 82),
            'png' => @imagepng($image, $tmp, 6),
            default => @imagejpeg($image, $tmp, 85),
        };
        if (!$saved || !@rename($tmp, $this->uploadDir . $outName)) {
            @unlink($tmp);
            return $name;
        }
        if ($outName !== $name) @unlink($path);
        return $outName;
    }

    /** Stima prudente della memoria che serve a GD per una foto di queste dimensioni. */
    private static function fitsInMemory(int $width, int $height): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit <= 0) return true;
        return memory_get_usage() + $width * $height * 5 * 2 + 8 * 1024 * 1024 < $limit;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return -1;
        $number = (int) $value;
        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3, 'm' => $number * 1024 ** 2, 'k' => $number * 1024, default => $number,
        };
    }

    /** L'orientamento EXIF di un JPEG (1 = dritto), letto anche senza l'estensione exif. */
    public static function jpegOrientation(string $path): int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            return is_array($exif) && isset($exif['Orientation']) ? max(1, min(8, (int) $exif['Orientation'])) : 1;
        }
        $data = (string) @file_get_contents($path, false, null, 0, 131072);
        $offset = 2;
        while ($offset + 4 <= strlen($data) && $data[$offset] === "\xFF") {
            $marker = ord($data[$offset + 1]);
            $length = unpack('n', substr($data, $offset + 2, 2))[1];
            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
                $tiff = substr($data, $offset + 10, $length - 8);
                $big = substr($tiff, 0, 2) === 'MM';
                $u16 = fn(int $at) => unpack($big ? 'n' : 'v', substr($tiff, $at, 2))[1] ?? 0;
                $u32 = fn(int $at) => unpack($big ? 'N' : 'V', substr($tiff, $at, 4))[1] ?? 0;
                if (strlen($tiff) < 8) return 1;
                $ifd = $u32(4);
                $entries = $u16($ifd);
                for ($i = 0; $i < $entries; $i++) {
                    $entry = $ifd + 2 + $i * 12;
                    if ($entry + 12 > strlen($tiff)) break;
                    if ($u16($entry) === 0x0112) return max(1, min(8, $u16($entry + 8)));
                }
                return 1;
            }
            if ($marker === 0xDA) break;
            $offset += 2 + $length;
        }
        return 1;
    }

    /** Applica l'orientamento EXIF ai pixel: dopo la ricodifica il tag non c'e piu. */
    private static function orient(\GdImage|false $image, int $orientation): \GdImage|false
    {
        if ($image === false || $orientation <= 1) return $image;
        if (in_array($orientation, [2, 4, 5, 7], true) && function_exists('imageflip')) {
            imageflip($image, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($orientation) { 3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0 };
        if ($angle !== 0) {
            $rotated = imagerotate($image, $angle, 0);
            if ($rotated !== false) $image = $rotated;
        }
        return $image;
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

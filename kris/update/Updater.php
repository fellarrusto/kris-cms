<?php
declare(strict_types=1);

namespace Kris\Update;

/**
 * Stadio 1 dell'aggiornamento: trova, scarica e verifica il pacchetto, poi
 * passa il controllo allo stadio 2 (installer.php) contenuto nel pacchetto
 * nuovo.
 *
 * Il canale e un file statico releases.json firmato, accanto a
 * releases.json.sig. Mai l'API di GitHub: ha un limite di richieste per IP,
 * condiviso fra tutti i siti di un hosting condiviso.
 *
 * Policy: dall'editor si installano solo versioni con la stessa major e non
 * marcate "breaking". Le altre vengono mostrate come riservate allo
 * sviluppatore.
 *
 * Attenzione all'operazione di installazione: dopo lo scambio delle cartelle
 * le classi gia caricate restano quelle vecchie. installPrepared() quindi non
 * usa SiteState, JsonStore o Migrator prima di passare il controllo.
 */
final class Updater
{
    public const DEFAULT_CHANNEL = 'https://raw.githubusercontent.com/fellarrusto/kris-cms/main/releases.json';
    public const KEEP_DAYS = 14;
    private const MANIFEST_MAX_BYTES = 512 * 1024;
    private const PREPARED_MAX_AGE = 3600;

    private string $root;
    private string $krisDir;
    private array $keys;
    private Http $http;
    private array $config;

    public function __construct(
        ?string $root = null,
        ?string $krisDir = null,
        ?array $keys = null,
        ?Http $http = null,
        ?array $config = null
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root ?? KRIS_ROOT), '/');
        $this->krisDir = rtrim(str_replace('\\', '/', $krisDir ?? KRIS_DIR), '/');
        $this->keys = $keys ?? Signature::trustedKeys();
        $this->http = $http ?? new Http();
        $this->config = $config ?? self::loadConfig($this->root);
    }

    /**
     * config/update.php, opzionale:
     *   return ['enabled' => true, 'channel' => 'https://…/releases.json'];
     */
    public static function loadConfig(string $root): array
    {
        $file = $root . '/config/update.php';
        $config = is_file($file) ? require $file : [];
        $config = is_array($config) ? $config : [];
        return [
            'enabled' => ($config['enabled'] ?? true) !== false,
            'channel' => is_string($config['channel'] ?? null) && $config['channel'] !== ''
                ? $config['channel'] : self::DEFAULT_CHANNEL,
        ];
    }

    public function enabled(): bool
    {
        return $this->config['enabled'];
    }

    public function currentVersion(): string
    {
        return trim((string) @file_get_contents($this->krisDir . '/VERSION'));
    }

    // --- controllo --------------------------------------------------------

    /**
     * Legge il canale e dice cosa si puo installare.
     *
     * @return array{current:string, target:?array, blocked:?array, unmet:?string, newer:array}
     */
    public function check(): array
    {
        $this->assertEnabled();
        $releases = $this->fetchReleases();
        return ['current' => $this->currentVersion()] + self::selectTarget($this->currentVersion(), $releases);
    }

    /**
     * Sceglie la versione da installare fra quelle del canale.
     *
     * target:  la piu alta installabile dall'editor (stessa major, non breaking,
     *          min_from rispettato, requisiti soddisfatti)
     * blocked: una versione piu alta che richiede lo sviluppatore
     * unmet:   il motivo per cui la piu alta installabile non va sull'hosting
     * newer:   tutte le versioni piu recenti, dalla piu alta, per il changelog
     */
    public static function selectTarget(string $current, array $releases, ?callable $requirementsError = null): array
    {
        $requirementsError ??= [self::class, 'requirementsError'];
        $major = self::major($current);
        $newer = array_values(array_filter($releases, fn($r) => is_array($r)
            && self::isVersion($r['version'] ?? null)
            && version_compare($r['version'], $current, '>')));
        usort($newer, fn($a, $b) => version_compare($b['version'], $a['version']));

        $target = null;
        $blocked = null;
        $unmet = null;
        foreach ($newer as $release) {
            $fromOk = !self::isVersion($release['min_from'] ?? null) || version_compare($current, $release['min_from'], '>=');
            $safe = self::major($release['version']) === $major && empty($release['breaking']);
            if (!$safe) {
                $blocked ??= $release;
                continue;
            }
            if (!$fromOk) {
                continue;
            }
            $problem = $requirementsError($release);
            if ($problem !== null) {
                $unmet ??= $problem;
                continue;
            }
            $target = $release;
            break;
        }
        // Una major bloccata interessa solo se e piu recente del target.
        if ($blocked !== null && $target !== null && version_compare($blocked['version'], $target['version'], '<')) {
            $blocked = null;
        }
        return ['target' => $target, 'blocked' => $blocked, 'unmet' => $target === null ? $unmet : null, 'newer' => $newer];
    }

    /** Motivo per cui l'hosting non soddisfa i requisiti della release, o null. */
    public static function requirementsError(array $release): ?string
    {
        $php = (string) ($release['requires']['php'] ?? '8.0');
        if (version_compare(PHP_VERSION, $php, '<')) {
            return "Kris {$release['version']} richiede PHP {$php}, l'hosting ha " . PHP_VERSION . '.';
        }
        foreach ((array) ($release['requires']['ext'] ?? []) as $ext) {
            if (is_string($ext) && !extension_loaded($ext)) {
                return "Kris {$release['version']} richiede l'estensione PHP {$ext}, che l'hosting non ha attiva.";
            }
        }
        return null;
    }

    // --- preparazione -----------------------------------------------------

    /** Scarica dal canale la versione installabile e la prepara. */
    public function prepareFromChannel(): array
    {
        $check = $this->check();
        $release = $check['target'];
        if ($release === null) {
            throw new UpdateException($check['unmet'] ?? 'Non ci sono aggiornamenti installabili da qui.');
        }
        if (!is_string($release['url'] ?? null) || !is_string($release['sha256'] ?? null)) {
            throw new UpdateException('Il canale non indica dove scaricare il pacchetto.');
        }

        $this->ensureDir($this->updatesDir());
        $zip = $this->updatesDir() . '/download-' . bin2hex(random_bytes(4)) . '.zip';
        try {
            $body = $this->http->get($release['url'], Package::MAX_ZIP_BYTES);
            if (!hash_equals(strtolower($release['sha256']), hash('sha256', $body))) {
                throw new UpdateException('Il pacchetto scaricato non corrisponde a quello firmato nel canale. Riprova più tardi.');
            }
            if (@file_put_contents($zip, $body) === false) {
                throw new UpdateException('Non riesco a salvare il pacchetto in data/updates/.');
            }
            return $this->prepareFromZip($zip, 'canale', $release['version']);
        } finally {
            @unlink($zip);
        }
    }

    /**
     * Prepara un pacchetto zip (scaricato o caricato a mano): lo estrae, ne
     * verifica firma e contenuto, controlla che sia installabile da qui.
     */
    public function prepareFromZip(string $zipFile, string $source, ?string $expectedVersion = null): array
    {
        $this->assertEnabled();
        $this->ensureDir($this->updatesDir());
        $this->discardPrepared();

        $dir = $this->updatesDir() . '/pacchetto-' . bin2hex(random_bytes(6));
        try {
            Package::extract($zipFile, $dir);
            $release = Package::verify($dir . '/kris', $this->keys);
            $this->assertInstallable($release, $expectedVersion);
        } catch (\Throwable $e) {
            self::removeTree($dir);
            throw $e instanceof UpdateException ? $e : new UpdateException('Il pacchetto non è utilizzabile: ' . $e->getMessage());
        }

        $prepared = [
            'version' => $release['version'],
            'from' => $this->currentVersion(),
            'dir' => basename($dir),
            'source' => $source,
            'prepared_at' => time(),
            'changelog' => (string) ($release['changelog_it'] ?? ''),
        ];
        file_put_contents($this->updatesDir() . '/prepared.json', json_encode($prepared, JSON_UNESCAPED_UNICODE));
        return $prepared;
    }

    /** Il pacchetto pronto per l'installazione, o null. */
    public function prepared(): ?array
    {
        $info = json_decode((string) @file_get_contents($this->updatesDir() . '/prepared.json'), true);
        if (!is_array($info) || !is_string($info['dir'] ?? null) || !preg_match('/^pacchetto-[0-9a-f]+$/', $info['dir'])) {
            return null;
        }
        if (time() - (int) ($info['prepared_at'] ?? 0) > self::PREPARED_MAX_AGE
            || ($info['from'] ?? null) !== $this->currentVersion()
            || !is_dir($this->updatesDir() . '/' . $info['dir'] . '/kris')) {
            return null;
        }
        return $info;
    }

    public function discardPrepared(): void
    {
        $info = json_decode((string) @file_get_contents($this->updatesDir() . '/prepared.json'), true);
        if (is_array($info) && is_string($info['dir'] ?? null) && preg_match('/^pacchetto-[0-9a-f]+$/', $info['dir'])) {
            self::removeTree($this->updatesDir() . '/' . $info['dir']);
        }
        @unlink($this->updatesDir() . '/prepared.json');
    }

    // --- installazione e ritorno indietro ----------------------------------

    /** Installa il pacchetto preparato passando il controllo al suo installer. */
    public function installPrepared(string $user): array
    {
        $this->assertEnabled();
        $info = $this->prepared();
        if ($info === null) {
            throw new UpdateException('Non c\'è un pacchetto pronto (o è scaduto): ripeti la verifica degli aggiornamenti.');
        }
        $package = $this->updatesDir() . '/' . $info['dir'] . '/kris';

        // Seconda verifica: fra la preparazione e ora il pacchetto e rimasto su disco.
        $release = Package::verify($package, $this->keys);
        $this->assertInstallable($release, $info['version']);
        @unlink($this->updatesDir() . '/prepared.json');

        require $package . '/update/installer.php';
        $result = \kris_update_install([
            'root' => $this->root,
            'package' => $package,
            'from' => $info['from'],
            'to' => $release['version'],
            'user' => $user,
            'release' => $release,
            'keep_days' => self::KEEP_DAYS,
        ]);
        self::removeTree($this->updatesDir() . '/' . $info['dir']);
        return $result;
    }

    /** Lo snapshot dell'ultimo aggiornamento riuscito, se si puo ancora tornare indietro. */
    public function availableRollback(): ?array
    {
        $current = $this->currentVersion();
        $best = null;
        foreach (glob($this->root . '/data/snapshots/*/meta.json') ?: [] as $file) {
            $meta = json_decode((string) @file_get_contents($file), true);
            if (!is_array($meta) || ($meta['outcome'] ?? '') !== 'ok' || ($meta['to'] ?? '') !== $current
                || !is_dir(dirname($file) . '/kris')) {
                continue;
            }
            $expires = strtotime((string) ($meta['expires_at'] ?? ''));
            if ($expires === false || $expires < time()) {
                continue;
            }
            if ($best === null || strcmp((string) $meta['created_at'], (string) $best['created_at']) > 0) {
                $best = $meta;
            }
        }
        return $best;
    }

    public function rollback(string $snapshotId, string $user): array
    {
        require $this->krisDir . '/update/installer.php';
        return \Kris\Install\rollback(['root' => $this->root, 'snapshot' => $snapshotId, 'user' => $user]);
    }

    public function recover(string $user): array
    {
        require $this->krisDir . '/update/installer.php';
        return \Kris\Install\recover(['root' => $this->root, 'user' => $user]);
    }

    // --- interni ----------------------------------------------------------

    private function fetchReleases(): array
    {
        $channel = $this->config['channel'];
        $manifest = $this->http->get($channel, self::MANIFEST_MAX_BYTES);
        $signature = $this->http->get($channel . '.sig', 4096);
        if (!Signature::verify($manifest, $signature, $this->keys)) {
            throw new UpdateException('La firma del canale degli aggiornamenti non è valida: nulla verrà installato.');
        }
        $data = json_decode($manifest, true);
        if (!is_array($data) || !is_array($data['releases'] ?? null)) {
            throw new UpdateException('Il canale degli aggiornamenti è illeggibile.');
        }
        return $data['releases'];
    }

    private function assertInstallable(array $release, ?string $expectedVersion): void
    {
        $current = $this->currentVersion();
        $version = (string) $release['version'];
        if (!self::isVersion($version)) {
            throw new UpdateException('Il pacchetto ha un numero di versione non valido.');
        }
        if ($expectedVersion !== null && $version !== $expectedVersion) {
            throw new UpdateException('Il pacchetto non è la versione attesa.');
        }
        if (version_compare($version, $current, '<=')) {
            throw new UpdateException("Il pacchetto contiene Kris {$version}, non più recente di quella installata ({$current}).");
        }
        if (self::major($version) !== self::major($current) || !empty($release['breaking'])) {
            throw new UpdateException("Kris {$version} cambia versione principale: va installata dallo sviluppatore, non dall'editor.");
        }
        if (self::isVersion($release['min_from'] ?? null) && version_compare($current, $release['min_from'], '<')) {
            throw new UpdateException("Kris {$version} si installa a partire dalla {$release['min_from']}: prima serve un aggiornamento intermedio.");
        }
        $problem = self::requirementsError($release);
        if ($problem !== null) {
            throw new UpdateException($problem);
        }
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled()) {
            throw new UpdateException('Gli aggiornamenti dall\'editor sono disattivati per questo sito.');
        }
    }

    private function updatesDir(): string
    {
        return $this->root . '/data/updates';
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new UpdateException('Non riesco a creare la cartella data/updates/: controlla i permessi.');
        }
    }

    private static function isVersion(mixed $v): bool
    {
        return is_string($v) && preg_match('/^\d+\.\d+\.\d+$/', $v) === 1;
    }

    private static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }

    public static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::removeTree($path . '/' . $item);
            }
        }
        @rmdir($path);
    }
}

<?php
declare(strict_types=1);

namespace Kris\Store;

use Kris\Site\Paths;
use PDO;
use Throwable;

/**
 * Adapter database (PDO: SQLite, MySQL/MariaDB). Un documento per riga in
 * <prefisso>documents, con una revisione intera che cresce a ogni scrittura.
 * La tabella si crea al primo uso.
 *
 * Scritture con revisione e transazioni sono serializzate: BEGIN IMMEDIATE
 * su SQLite, GET_LOCK su MySQL. $preview come FileStore: un documento
 * modificato nell'anteprima dello staging si legge dal suo file.
 */
final class PdoStore implements Store
{
    private PDO $pdo;
    private string $table;
    private bool $sqlite;
    private int $depth = 0;

    public function __construct(private string $root, array $config, private bool $preview = false)
    {
        $this->root = rtrim($root, '/\\');
        $prefix = (string) ($config['prefix'] ?? 'kris_');
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/', $prefix)) throw new StoreException('Prefisso delle tabelle non valido in config/store.php.');
        $this->table = $prefix . 'documents';
        try {
            $dsn = (string) ($config['dsn'] ?? '');
            // SQLite con un percorso relativo: dalla root del sito, non dalla cartella corrente
            if (preg_match('#^sqlite:(?!:memory:|/|\\\\|[A-Za-z]:)(.+)$#', $dsn, $m)) $dsn = 'sqlite:' . $this->root . '/' . $m[1];
            $this->pdo = new PDO($dsn, $config['user'] ?? null, $config['password'] ?? null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->sqlite = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
            $body = $this->sqlite ? 'TEXT' : 'LONGTEXT';
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$this->table} (name VARCHAR(64) NOT NULL PRIMARY KEY, rev BIGINT NOT NULL, body {$body} NOT NULL)");
            if ($this->sqlite) $this->pdo->exec('PRAGMA busy_timeout = 10000');
        } catch (Throwable $e) {
            // Il messaggio del driver può contenere il DSN: resta nel log
            error_log('Kris store: ' . $e->getMessage());
            throw new StoreException('Il database dei contenuti non risponde: controlla config/store.php.');
        }
    }

    public function read(string $doc): Snapshot
    {
        $relative = FileStore::relativePath($doc);
        if ($this->preview && $this->root === rtrim(KRIS_ROOT, '/\\')) {
            $file = Paths::file($relative);
            if ($file !== $this->root . '/' . $relative && is_file($file)) {
                $raw = (string) file_get_contents($file);
                $value = json_decode($raw, true);
                return new Snapshot(is_array($value) ? $value : [], hash('sha256', $raw));
            }
        }
        [$value, $rev] = $this->row($doc);
        return new Snapshot($value, $rev);
    }

    public function write(string $doc, array $value, ?string $expectedRev = null): string
    {
        FileStore::relativePath($doc);
        return $this->locked(function () use ($doc, $value, $expectedRev): string {
            $current = $this->row($doc)[1];
            if ($expectedRev !== null && $current !== $expectedRev) throw new ConflictException($doc, $current);
            return $this->put($doc, $value, $current);
        });
    }

    public function transaction(array $docs, callable $change): void
    {
        $this->locked(function () use ($docs, $change): void {
            $current = [];
            foreach ($docs as $doc) {
                FileStore::relativePath($doc);
                $current[$doc] = $this->row($doc);
            }
            $changed = $change(array_map(fn(array $row): array => $row[0], $current));
            if (!is_array($changed)) return;
            foreach (array_keys($changed) as $doc) {
                if (!in_array($doc, $docs, true)) {
                    throw new StoreException("La transazione non ha letto «{$doc}»: non può scriverlo.");
                }
            }
            foreach ($changed as $doc => $value) $this->put($doc, $value, $current[$doc][1]);
        });
    }

    public function export(): array
    {
        $out = [];
        foreach (Store::DOCUMENTS as $doc) {
            [$value, $rev] = $this->row($doc);
            if ($rev !== '') $out[$doc] = $value;
        }
        return $out;
    }

    public function import(array $documents): void
    {
        $this->transaction(array_keys($documents), fn(): array => $documents);
    }

    /** @return array{0: array, 1: string} */
    private function row(string $doc): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT rev, body FROM {$this->table} WHERE name = ?");
            $stmt->execute([$doc]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            error_log('Kris store: ' . $e->getMessage());
            throw new StoreException("Il database non ha restituito «{$doc}».");
        }
        if ($row === false) return [[], ''];
        $value = json_decode((string) $row['body'], true);
        if (!is_array($value)) throw new StoreException("Il documento «{$doc}» nel database è rovinato.");
        return [$value, (string) $row['rev']];
    }

    private function put(string $doc, array $value, string $currentRev): string
    {
        $body = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        if ($body === false) throw new StoreException("Il documento «{$doc}» non si può salvare: " . json_last_error_msg());
        $rev = (int) $currentRev + 1;
        $sql = $currentRev === ''
            ? "INSERT INTO {$this->table} (rev, body, name) VALUES (?, ?, ?)"
            : "UPDATE {$this->table} SET rev = ?, body = ? WHERE name = ?";
        try {
            $this->pdo->prepare($sql)->execute([$rev, $body, $doc]);
        } catch (Throwable $e) {
            error_log('Kris store: ' . $e->getMessage());
            throw new StoreException("Il database non ha salvato «{$doc}».");
        }
        return (string) $rev;
    }

    /** Esegue $work in una transazione esclusiva; rientrante nella stessa richiesta. */
    private function locked(callable $work): mixed
    {
        if ($this->depth > 0) {
            $this->depth++;
            try {
                return $work();
            } finally {
                $this->depth--;
            }
        }
        $named = 'kris_store_' . $this->table;
        try {
            if ($this->sqlite) {
                $this->pdo->exec('BEGIN IMMEDIATE');
            } else {
                $got = $this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($named) . ', 10)')->fetchColumn();
                if ((string) $got !== '1') throw new StoreException("Impossibile ottenere il lock dell'archivio.");
                $this->pdo->beginTransaction();
            }
        } catch (StoreException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('Kris store: ' . $e->getMessage());
            throw new StoreException("Impossibile ottenere il lock dell'archivio.");
        }
        $this->depth = 1;
        try {
            $result = $work();
            $this->sqlite ? $this->pdo->exec('COMMIT') : $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            try {
                $this->sqlite ? $this->pdo->exec('ROLLBACK') : $this->pdo->rollBack();
            } catch (Throwable) {
            }
            throw $e;
        } finally {
            $this->depth = 0;
            if (!$this->sqlite) {
                try {
                    $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($named) . ')');
                } catch (Throwable) {
                }
            }
        }
    }
}

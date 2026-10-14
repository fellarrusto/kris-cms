<?php
declare(strict_types=1);

/**
 * Lo staging di un sito Kris da riga di comando: le stesse operazioni che
 * fara l'agente, per provarle senza Worker e senza MCP.
 *
 *   php tools/staging.php status
 *   php tools/staging.php read  template/homepage.html
 *   php tools/staging.php edit  template/homepage.html --old="Chi siamo" --new="Il team"
 *   php tools/staging.php write assets/css/extra.css < extra.css     (file nuovo o sostituito)
 *   php tools/staging.php promote [--force]
 *   php tools/staging.php discard
 *   php tools/staging.php undo
 *
 * --root=<cartella> lavora su un altro sito; di default il sito di questa repository.
 * L'anteprima si apre da admin con index.php?kris_preview=1.
 */

$args = array_slice($argv, 1);
$options = [];
$positional = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/s', $arg, $m)) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        $positional[] = $arg;
    }
}

if (isset($options['root'])) {
    define('KRIS_ROOT', rtrim((string) realpath((string) $options['root']), '/\\'));
}
require_once dirname(__DIR__) . '/kris/bootstrap.php';

use Kris\Staging\Diff;
use Kris\Staging\Staging;
use Kris\Staging\StagingException;

$staging = new Staging();
$by = 'cli:' . (get_current_user() ?: 'sviluppatore');
[$command, $file] = [$positional[0] ?? 'status', $positional[1] ?? null];

function out(string $text): void
{
    fwrite(STDOUT, $text . PHP_EOL);
}

try {
    switch ($command) {
        case 'status':
            $changes = $staging->changes();
            if (!$changes) {
                out('Nessuna modifica in attesa.');
            }
            foreach ($changes as $change) {
                out(sprintf('%s  %s%s', $change['kind'] === 'new' ? 'nuovo     ' : 'modificato', $change['file'], $change['conflict'] ? '  [cambiato anche sul sito]' : ''));
                foreach ($change['sensitive'] as $item) out("    ! {$item}");
                foreach (Diff::lines($change['site'] ?? '', $change['staged']) as [$op, $line]) out("    {$op} {$line}");
            }
            $last = $staging->lastPromotion();
            if ($last) out("Ultima pubblicazione annullabile: {$last['id']} ({$last['promoted_by']})");
            break;

        case 'read':
            $read = $staging->read((string) $file);
            if ($read['content'] === null) throw new StagingException("Il file '{$file}' non esiste.");
            fwrite(STDERR, "# fonte: {$read['source']}  impronta: {$read['hash']}" . PHP_EOL);
            fwrite(STDOUT, $read['content']);
            break;

        case 'edit':
            if (!is_string($options['old'] ?? null) || !is_string($options['new'] ?? null)) {
                throw new StagingException('Servono --old="testo" e --new="testo".');
            }
            $current = $staging->read((string) $file);
            $staging->edit((string) $file, $options['old'], $options['new'], (string) $current['hash'], $by);
            out("Modificato nello staging: {$file}");
            break;

        case 'write':
            $current = $staging->read((string) $file);
            $staging->write((string) $file, (string) stream_get_contents(STDIN), $current['hash'], $by);
            out("Scritto nello staging: {$file}");
            break;

        case 'promote':
            $done = $staging->promote($by, isset($options['force']));
            out('Pubblicati: ' . implode(', ', $done['files']) . " (copia di sicurezza {$done['backup']})");
            break;

        case 'discard':
            $staging->discard();
            out('Staging scartato.');
            break;

        case 'undo':
            out('Ripristinati: ' . implode(', ', $staging->undoLastPromotion()));
            break;

        default:
            throw new StagingException("Comando sconosciuto: {$command}. Usa status, read, edit, write, promote, discard o undo.");
    }
} catch (StagingException $e) {
    fwrite(STDERR, 'Errore: ' . $e->getMessage() . ($e->details() ? ' (' . implode(', ', $e->details()) . ')' : '') . PHP_EOL);
    exit(1);
}

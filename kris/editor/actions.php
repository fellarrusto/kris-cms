<?php
declare(strict_types=1);

use Kris\Entity\MediaStore;
use Kris\Entity\StorageException;
use Kris\Staging\EditorChanges;
use Kris\Staging\StagingException;
use Kris\Store\StoreException;
if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * Azioni POST del pannello. Contenuti e struttura diventano operazioni
 * (editorApply): sul sito pubblico si applicano subito, in «In attesa» si
 * registrano nello staging. Le altre azioni (account, impostazioni, Kris
 * Agent, pubblicazione, libreria) valgono uguali nelle due modalità.
 *
 * Richiede $stagingMode, $editorLock, $BASE, $uploadDir, $uploadUrl,
 * $DEFAULT_LANGS, $msg ed $error da index.php.
 */

if (!kris_is_logged_in()) { http_response_code(403); exit; }

$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$ajax = (string) ($_SERVER['HTTP_X_KRIS_EDITOR'] ?? '');
$g = is_string($_POST['group'] ?? null) ? $_POST['group'] : (is_string($_POST['group_name'] ?? null) ? $_POST['group_name'] : '');
$goTo = function (string $query) use ($BASE): never {
    header("Location: {$BASE}{$query}");
    exit;
};
$languages = editorLanguages();

if ($isPost && !kris_csrf_valid($_POST['csrf'] ?? null)) {
    $error = 'Sessione scaduta o richiesta non valida: ricarica la pagina e riprova. Nulla è stato modificato.';
} elseif ($isPost && $editorLock !== '') {
    $error = $editorLock . ' Nulla è stato modificato.';
} elseif ($isPost && isReservedStructuralPost($_POST)) {
    $error = 'I nomi che iniziano con kris_ o plg_ sono riservati: queste raccolte hanno una struttura fissa. Nulla è stato modificato.';
} elseif ($isPost && $stagingMode && !isStagingNeutralPost($_POST) && $g !== '' && isReservedCollection($g) && !in_array($g, ['kris_site', 'kris_notfound', 'kris_wip'], true)) {
    $error = 'Privacy e cookie si modificano sul sito pubblico: passa a «Sito pubblico» per cambiarli.';
} elseif ($isPost && $stagingMode && array_intersect(['create_collection', 'save_structure', 'delete_collection', 'save_collection_info', 'toggle_post_collection', 'save_settings', 'delete_media', 'optimize_media'], array_keys($_POST))) {
    $error = 'Struttura, lingue e file della libreria si cambiano sul sito pubblico: passa a «Sito pubblico».';
} elseif ($isPost) {
  try {
    $pending = $stagingMode;
    $saved = $stagingMode
        ? 'Modifiche messe in attesa: il sito pubblico non cambia finché non le pubblichi.'
        : 'Contenuto salvato: è già visibile sul sito.';

    // --- Contenuti ---------------------------------------------------------
    if (isset($_POST['save_entity'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        $node = count($path) % 2 === 0 ? editorNode($g, $id, $path) : null;
        if ($node === null) throw new StagingException('Questo contenuto non esiste più: ricarica la pagina.');
        $fields = editorFields($g, editorListNames($path));
        $before = is_array($node['values'] ?? null) ? $node['values'] : [];
        $after = editorPostedValues($fields, $_POST, $languages, $before);
        $ops = EditorChanges::fieldOps($g, $id, $path, $fields, $before, $after, $languages);
        editorApply($ops, $pending);
        $msg = $ops ? $saved : 'Nessuna modifica da salvare.';
    }
    if (isset($_POST['create_instance'])) {
        $done = editorApply([['op' => 'create_entity', 'key' => $g]], $pending);
        $newId = (int) $done[0]['assigned_id'];
        // In una raccolta di articoli il più recente va in cima
        if (isNewestFirst($g)) editorApply([['op' => 'move_entity', 'key' => $g, 'id' => $newId, 'to' => 0]], $pending);
        $goTo('?action=edit&group=' . urlencode($g) . '&id=' . $newId);
    }
    if (isset($_POST['create_nested'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        if (count($path) % 2 !== 1) throw new StagingException('Elenco non valido: ricarica la pagina.');
        $done = editorApply([['op' => 'add_item', 'key' => $g, 'id' => $id, 'path' => array_slice($path, 0, -1), 'array' => (string) end($path)]], $pending);
        $goTo('?action=edit&group=' . urlencode($g) . "&id={$id}&path=" . urlencode(pathToString([...$path, (string) $done[0]['assigned_id']])));
    }
    if (isset($_POST['delete_instance'])) {
        editorApply([['op' => 'remove_entity', 'key' => $g, 'id' => (int) ($_POST['id'] ?? -1)]], $pending);
        $goTo('?action=list&group=' . urlencode($g));
    }
    if (isset($_POST['delete_nested'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        if (count($path) < 2 || count($path) % 2 !== 0) throw new StagingException('Elemento non valido: ricarica la pagina.');
        $parent = array_slice($path, 0, -2);
        editorApply([['op' => 'remove_item', 'key' => $g, 'id' => $id, 'path' => $parent,
            'array' => (string) $path[count($path) - 2], 'item_id' => (int) $path[count($path) - 1]]], $pending);
        $goTo('?action=edit&group=' . urlencode($g) . "&id={$id}" . ($parent ? '&path=' . urlencode(pathToString($parent)) : '') . returnAnchor());
    }
    if (isset($_POST['toggle_visibility'])) {
        $visible = ($_POST['visible'] ?? '') === '1';
        $path = parsePath((string) ($_POST['path'] ?? ''));
        if (count($path) % 2 !== 0 || isReservedCollection($g)) throw new StagingException('Elemento non trovato: ricarica la pagina.');
        editorApply([['op' => 'set_hidden', 'key' => $g, 'id' => (int) ($_POST['id'] ?? -1), 'path' => $path, 'hidden' => !$visible]], $pending);
        $msg = $stagingMode
            ? ($visible ? 'In attesa: tornerà visibile quando pubblichi.' : 'In attesa: verrà sospeso quando pubblichi.')
            : ($visible ? 'Visibile sul sito.' : 'Sospeso: il sito non lo mostra.');
        if ($ajax !== 'visibility') {
            $back = is_string($_POST['return_to'] ?? null) && preg_match('/^\?action=[a-z_]+(&[A-Za-z0-9_=%.\/-]*)*$/', $_POST['return_to'])
                ? $_POST['return_to'] : '?action=list&group=' . urlencode($g);
            $goTo($back);
        }
    }
    if (isset($_POST['reorder_root'])) {
        $before = array_map(fn($item) => (int) $item['id'], editorItems($g));
        $after = array_map('intval', explode(',', (string) ($_POST['order'] ?? '')));
        editorApply(array_map(fn($move) => ['op' => 'move_entity', 'key' => $g, 'id' => $move[0], 'to' => $move[1]], EditorChanges::moves($before, $after)), $pending);
        $msg = $stagingMode ? 'Ordine messo in attesa.' : 'Ordine salvato.';
        if ($ajax !== 'reorder') $goTo('?action=list&group=' . urlencode($g));
    }
    if (isset($_POST['reorder_nested'])) {
        $id = (int) ($_POST['id'] ?? -1);
        $path = parsePath((string) ($_POST['path'] ?? ''));
        if (count($path) % 2 !== 1) throw new StagingException('Elenco non disponibile: ricarica il contenuto prima di riprovare.');
        $parent = array_slice($path, 0, -1);
        $list = (string) end($path);
        $owner = editorNode($g, $id, $parent);
        if ($owner === null || !is_array($owner['values'][$list] ?? null)) throw new StagingException('Elenco non disponibile: ricarica il contenuto prima di riprovare.');
        $before = array_map(fn($child) => (int) ($child['id'] ?? -1), $owner['values'][$list]);
        $after = array_map('intval', explode(',', (string) ($_POST['order'] ?? '')));
        editorApply(array_map(fn($move) => ['op' => 'move_item', 'key' => $g, 'id' => $id, 'path' => $parent, 'array' => $list,
            'item_id' => $move[0], 'to' => $move[1]], EditorChanges::moves($before, $after)), $pending);
        $msg = $stagingMode ? 'Ordine messo in attesa.' : 'Ordine salvato.';
        if ($ajax !== 'reorder') $goTo('?action=edit&group=' . urlencode($g) . "&id={$id}" . ($parent ? '&path=' . urlencode(pathToString($parent)) : ''));
    }

    // --- Struttura (solo sul sito pubblico) ------------------------------------
    if (isset($_POST['create_collection'])) {
        $name = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_POST['collection_name'] ?? '')));
        $label = mb_substr(trim((string) ($_POST['collection_label'] ?? '')), 0, 60);
        if ($name === '' || !preg_match('/^[a-z]/', $name)) throw new StagingException('Il nome tecnico deve iniziare con una lettera: lettere minuscole, numeri e _.');
        $ops = [['op' => 'create_collection', 'name' => $name, 'fields' => []]];
        if ($label !== '' || isset($_POST['collection_single'])) {
            $ops[] = ['op' => 'set_collection_info', 'collection' => $name] + ($label !== '' ? ['label' => $label] : []) + (isset($_POST['collection_single']) ? ['single' => true] : []);
        }
        editorApply($ops, false);
        $goTo('?action=structure&group=' . urlencode($name));
    }
    if (isset($_POST['save_structure'])) {
        $decoded = json_decode((string) ($_POST['schema_json'] ?? ''), true);
        if (!is_array($decoded) || !array_is_list($decoded) || editorType($g) === null) {
            // Uno schema illeggibile non deve diventare uno schema vuoto
            throw new StagingException('Struttura non salvata: i dati inviati non sono validi. Nulla è stato modificato.');
        }
        $new = editorCleanSchema($decoded);
        $problems = validateSchema($new);
        if ($problems) throw new StagingException('Struttura non salvata: ' . implode(' ', $problems));
        $old = editorFields($g);
        $impact = isset($_POST['confirm_impact']) ? [] : editorSchemaImpact($g, $old, $new);
        if ($impact) {
            // Non si salva nulla: si mostra cosa si perderebbe e si chiede conferma
            $pendingImpact = $impact;
            $pendingSchema = json_encode($new, JSON_UNESCAPED_UNICODE);
            $group = $g;
            $action = 'structure_impact';
        } else {
            $ops = editorSchemaOps($g, $old, $new);
            editorApply($ops, false);
            $msg = $ops ? 'Struttura salvata.' : 'La struttura era già così.';
        }
    }
    if (isset($_POST['delete_collection'])) {
        editorApply([['op' => 'remove_collection', 'name' => $g]], false);
        $goTo('');
    }
    if (isset($_POST['save_collection_info'])) {
        $single = isset($_POST['collection_single']);
        if ($single && count(editorItems($g)) > 1) throw new StagingException('Un contenuto unico vale solo con un contenuto: questa raccolta ne ha di più.');
        $types = array_keys(editorUserTypes());
        $position = max(0, min((int) ($_POST['collection_position'] ?? 0), count($types) - 1));
        $ops = [['op' => 'set_collection_info', 'collection' => $g,
            'label' => mb_substr(trim((string) ($_POST['collection_label'] ?? '')), 0, 60),
            'description' => mb_substr(trim((string) ($_POST['collection_description'] ?? '')), 0, 200),
            'single' => $single]];
        // La posizione è fra le raccolte del sito
        if (array_search($g, $types, true) !== $position) $ops[] = ['op' => 'move_collection', 'collection' => $g, 'to' => $position];
        editorApply($ops, false);
        $msg = 'Nome e posizione salvati.';
    }
    if (isset($_POST['toggle_post_collection'])) {
        $enabled = ($_POST['enabled'] ?? '') === '1';
        editorApply([['op' => 'set_post_collection', 'collection' => $g, 'enabled' => $enabled]], false);
        $msg = $enabled ? 'Raccolta di articoli: i nuovi contenuti vanno in cima.' : 'I nuovi contenuti vanno in fondo.';
    }

    // --- Impostazioni del sito ----------------------------------------------
    if (isset($_POST['save_settings'])) {
        $chosen = is_array($_POST['langs'] ?? null) ? array_values(array_intersect(array_keys($DEFAULT_LANGS), $_POST['langs'])) : [];
        $main = (string) ($_POST['main_lang'] ?? '');
        if ($chosen === []) throw new StagingException('Scegli almeno una lingua. Le impostazioni non sono state modificate.');
        // La principale va per prima: è quella degli indirizzi senza prefisso
        if (in_array($main, $chosen, true)) $chosen = [$main, ...array_values(array_diff($chosen, [$main]))];
        \Kris\Store\Stores::site()->transaction(['settings'], function (array $docs) use ($chosen): array {
            return ['settings' => ['languages' => $chosen] + $docs['settings']];
        });
        editorReload($stagingMode);
        $msg = 'Lingue salvate. Le traduzioni delle lingue disattivate restano conservate.';
    }
    if (isset($_POST['save_upload_settings'])) {
        $uploads = [
            'prefix' => MediaStore::slug(mb_substr((string) ($_POST['upload_prefix'] ?? ''), 0, 60)),
            'max_width' => max(0, min(10000, (int) ($_POST['upload_max_width'] ?? MediaStore::DEFAULT_MAX_WIDTH))),
            'webp' => isset($_POST['upload_webp']) && MediaStore::canWriteWebp(),
        ];
        \Kris\Store\Stores::site()->transaction(['settings'], fn(array $docs): array => ['settings' => ['uploads' => $uploads] + $docs['settings']]);
        editorReload($stagingMode);
        $msg = 'Impostazioni dei caricamenti salvate.';
    }

    // --- Account -----------------------------------------------------------
    if (isset($_POST['save_account_email'])) {
        $email = trim((string) ($_POST['account_email'] ?? ''));
        $config = kris_auth_config() ?? [];
        if (!Kris\Auth\Credentials::validEmail($email)) {
            $error = 'Indirizzo email non valido: nulla è stato modificato.';
        } elseif (!kris_credentials()->verify((string) ($config['user'] ?? ''), (string) ($_POST['current_password'] ?? ''))) {
            // L'email riceve i link di reset: cambiarla vale quanto cambiare la password
            $error = 'Password non corretta: l\'email non è stata modificata.';
        } elseif (!kris_credentials()->save(['email' => $email, 'editor_url' => kris_current_editor_url()])) {
            $error = 'Non riesco a scrivere config/auth.php: controlla i permessi con l\'hosting.';
        } else {
            $msg = 'Email salvata: la userai per recuperare la password. Prova a inviarti un\'email di prova.';
        }
    }
    if (isset($_POST['account_email_later'])) {
        $_SESSION['kris_email_later'] = true;
        $goTo('?' . (string) ($_SERVER['QUERY_STRING'] ?? ''));
    }
    if (isset($_POST['send_test_email'])) {
        $config = kris_auth_config() ?? [];
        $sent = kris_send_mail((string) ($config['email'] ?? ''), 'Email di prova dall\'editor',
            "Ciao,\n\nse leggi questo messaggio l'hosting invia correttamente le email del sito:\npotrai recuperare la password dell'editor se la dimentichi.\n");
        if ($sent) {
            $msg = 'Email di prova inviata a ' . ($config['email'] ?? '') . '. Se non arriva entro qualche minuto (controlla lo spam), il recupero via email non funzionerà su questo hosting.';
        } else {
            $error = 'L\'hosting non ha accettato l\'invio: il recupero della password via email non funzionerà. ' . KRIS_HOSTING_HELP;
        }
    }
    if (isset($_POST['change_password'])) {
        $config = kris_auth_config() ?? [];
        $new = (string) ($_POST['new_password'] ?? '');
        if (!kris_credentials()->verify((string) ($config['user'] ?? ''), (string) ($_POST['current_password'] ?? ''))) {
            $error = 'La password attuale non è corretta: nulla è stato modificato.';
        } elseif (strlen($new) < Kris\Auth\Credentials::MIN_PASSWORD) {
            $error = 'La nuova password deve avere almeno 10 caratteri.';
        } elseif ($new !== (string) ($_POST['new_password2'] ?? '')) {
            $error = 'Le due nuove password non coincidono.';
        } elseif (!kris_credentials()->save(['password' => $new])) {
            $error = 'Non riesco a scrivere config/auth.php: controlla i permessi con l\'hosting.';
        } else {
            session_regenerate_id(true);
            $msg = 'Password aggiornata.';
        }
    }

    // --- Kris Agent --------------------------------------------------------
    if (isset($_POST['agent_grant']) || isset($_POST['agent_reset'])) {
        try {
            $workerClient = new \Kris\Agent\WorkerClient(new \Kris\Agent\AgentConfig());
            if (isset($_POST['agent_reset'])) {
                $workerClient->resetAccess();
                $msg = 'Accesso reimpostato: chiavi nuove, e ogni collaboratore aspetta di nuovo la tua approvazione.';
            } else {
                $grantStatus = ($_POST['status'] ?? '') === 'approved' ? 'approved' : 'revoked';
                $workerClient->decide((string) $_POST['agent_grant'], $grantStatus);
                $msg = $grantStatus === 'approved' ? 'Collaboratore approvato.' : 'Collaboratore escluso: non può più usare l\'assistente su questo sito.';
            }
        } catch (\Kris\Agent\AgentException $e) {
            $error = $e->getMessage();
        }
    }
    if (isset($_POST['agent_pair']) || isset($_POST['agent_toggle']) || isset($_POST['agent_unpair'])) {
        $agentConfig = new \Kris\Agent\AgentConfig();
        try {
            if (isset($_POST['agent_pair'])) {
                $siteUrl = (string) preg_replace('#/(kris-admin|editor)(/index\.php)?$#', '', kris_current_editor_url());
                \Kris\Agent\Pairing::pair($agentConfig, (string) ($_POST['agent_code'] ?? ''), $siteUrl, $siteUrl . '/kris-agent.php',
                    $krisVersion, (string) ($_SESSION['kris_user'] ?? ''));
                \Kris\Update\Stubs::ensure(KRIS_ROOT);
                $msg = 'Sito abbinato a Kris Agent.';
            } elseif (isset($_POST['agent_toggle'])) {
                $agentConfig->setEnabled($_POST['agent_toggle'] === '1');
                $msg = $_POST['agent_toggle'] === '1' ? 'Kris Agent riacceso.' : 'Kris Agent spento: le sue richieste vengono rifiutate.';
            } else {
                $agentConfig->delete();
                $msg = 'Sito scollegato da Kris Agent.';
            }
        } catch (\Kris\Agent\AgentException $e) {
            $error = $e->getMessage();
        }
    }

    // --- Da pubblicare -----------------------------------------------------
    if (isset($_POST['approve_code'])) {
        (new \Kris\Staging\Staging())->approveCode((string) $_POST['approve_code'], (string) ($_SESSION['kris_user'] ?? ''));
        $msg = 'Script approvati: l\'anteprima ora li esegue.';
        // Dal popup si torna alla stessa pagina dell'anteprima
        $return = (string) ($_POST['return'] ?? '');
        if (preg_match('/^[A-Za-z0-9_=&%.\/-]*kris_preview=1[A-Za-z0-9_=&%.\/-]*$/', $return) === 1) {
            header('Location: ' . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/index.php?' . $return);
            exit;
        }
    }
    if (isset($_POST['staging_promote']) || isset($_POST['staging_discard']) || isset($_POST['staging_undo'])) {
        $staging = new \Kris\Staging\Staging();
        $user = (string) ($_SESSION['kris_user'] ?? '');
        if (isset($_POST['staging_undo'])) {
            $restored = $staging->undoLastPromotion();
            $msg = 'Pubblicazione annullata: ' . count($restored) . ' file sono tornati com\'erano.';
        } else {
            if (isset($_POST['staging_promote'])) {
                $done = $staging->promote($user, isset($_POST['force']));
                $msg = 'Modifiche pubblicate: ' . count($done['files']) . ' file. Puoi annullare la pubblicazione per ' . \Kris\Staging\Staging::BACKUP_DAYS . ' giorni.';
            } else {
                $staging->discard();
                $msg = 'Modifiche scartate. Il sito non è cambiato.';
            }
            // Non c'è più niente in attesa: l'editor torna al sito pubblico
            $_SESSION['kris_editing'] = 'live';
            $stagingMode = false;
        }
        editorReload($stagingMode);
    }

    // --- Libreria media ----------------------------------------------------
    if (isset($_FILES['file'])) {
        if ($stagingMode) {
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
                throw new StagingException('Caricamento non riuscito: riprova.');
            }
            (new \Kris\Staging\Staging())->upload((string) $file['tmp_name'], (string) ($file['name'] ?? ''), 'editor: ' . (string) ($_SESSION['kris_user'] ?? ''));
            $msg = 'File caricato fra le modifiche in attesa: sarà pubblico quando pubblichi.';
        } else {
            (new MediaStore($uploadDir, $uploadUrl))->store($_FILES['file']);
            $msg = 'File caricato.';
        }
    }
    if (isset($_POST['optimize_media'])) {
        // A blocchi, per non superare il tempo massimo dell'hosting
        $start = microtime(true);
        $made = 0;
        $left = 0;
        foreach (editorMediaFiles($uploadDir) as $file) {
            if (microtime(true) - $start > 20) { $left++; continue; }
            $made += MediaStore::makeVariants($uploadDir, basename($file));
        }
        $msg = $left > 0
            ? "Create {$made} misure ottimizzate. Restano {$left} immagini: premi di nuovo «Ottimizza le immagini»."
            : ($made > 0 ? "Create {$made} misure ottimizzate: le pagine ora caricano quella adatta allo schermo." : 'Le immagini sono già tutte ottimizzate.');
    }
    if (isset($_POST['delete_media'])) {
        // basename() impedisce di uscire dalla cartella dei caricamenti
        $filename = basename((string) ($_POST['file_name'] ?? ''));
        if ($filename !== '' && is_file($uploadDir . $filename)) {
            if (MediaStore::deleteWithVariants($uploadDir, $filename)) $msg = 'File eliminato.';
            else $error = 'Non è stato possibile eliminare il file. Riprova.';
        } else {
            $error = 'Il file non è più disponibile: la libreria potrebbe essere stata aggiornata.';
        }
    }
  } catch (StagingException $e) {
    // Nessuna conferma se la scrittura non è riuscita: i dati sono quelli di prima
    $msg = '';
    $error = $e->getMessage();
  } catch (StorageException | StoreException $e) {
    $msg = '';
    $error = 'Non siamo riusciti a salvare. I contenuti non sono stati modificati. (' . $e->getMessage() . ')';
  }
}

// Le richieste asincrone ricevono l'esito in JSON: il browser conserva il modulo fino alla conferma
if ($isPost && (($ajax === 'save' && (isset($_POST['save_entity']) || isset($_POST['save_settings'])))
    || ($ajax === 'reorder' && (isset($_POST['reorder_root']) || isset($_POST['reorder_nested'])))
    || ($ajax === 'visibility' && isset($_POST['toggle_visibility']))
    || ($ajax === 'account' && (isset($_POST['save_account_email']) || isset($_POST['send_test_email']) || isset($_POST['change_password']))))) {
    http_response_code($error !== '' ? 422 : 200);
    header('Content-Type: application/json; charset=utf-8');
    $warnings = $error === '' ? editorWarnings() : [];
    echo json_encode(['ok' => $error === '' && $msg !== '', 'message' => $error ?: $msg . ($warnings ? ' ' . implode(' ', $warnings) : ''),
        'csrf' => kris_csrf_token()], JSON_UNESCAPED_UNICODE);
    exit;
}

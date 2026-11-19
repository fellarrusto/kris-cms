<?php
declare(strict_types=1);

use Kris\Auth\UserException;
use Kris\Entity\MediaStore;
use Kris\Entity\StorageException;
use Kris\Staging\Operations;
use Kris\Staging\Staging;
use Kris\Staging\StagingException;
use Kris\Store\StoreException;

if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno

/**
 * API JSON dell'editor (l'app Vue in kris/editor/dist/). Si chiama con
 * index.php?api=<nome>: GET per leggere, POST con corpo JSON e intestazione
 * X-Kris-Csrf per scrivere. Risponde sempre {ok: true, ...} oppure
 * {ok: false, error}. L'accesso resta quello delle pagine PHP (sessione).
 *
 * Contenuti e struttura sono operazioni (Operations) applicate nella modalità
 * in uso: subito sul sito con «Sito pubblico», in attesa con «In attesa».
 * L'unico potere in più dell'admin è gestire gli utenti.
 *
 * Richiede da index.php: $stagingMode, $editorLock, $migrationsPending,
 * $maintenance, $frameworkNotice, $updateHint, $krisVersion, $uploadDir,
 * $uploadUrl, $DEFAULT_LANGS.
 */

/** Operazioni che cambiano la struttura: solo sul sito pubblico. */
const KRIS_API_SCHEMA_OPS = ['add_field', 'remove_field', 'rename_field', 'describe_field', 'change_type', 'move_field', 'set_options',
    'create_collection', 'rename_collection', 'remove_collection', 'set_post_collection', 'set_collection_info', 'move_collection',
    'register_page', 'unregister_page', 'set_sitemap'];

function kris_api_respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/** Un errore da mostrare a chi usa l'editor. */
final class ApiError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly array $details = [])
    {
        parent::__construct($message);
    }
}

// Le risposte JSON non passano dal filtro che aggiunge il token ai moduli HTML (index.php)
while (ob_get_level() > 0) ob_end_clean();

$apiName = is_string($_GET['api'] ?? null) ? $_GET['api'] : '';
$apiPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$body = [];
if ($apiPost) {
    $raw = (string) file_get_contents('php://input');
    $body = $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) kris_api_respond(['ok' => false, 'error' => 'Richiesta non valida.'], 400);
    if (!kris_csrf_valid($_SERVER['HTTP_X_KRIS_CSRF'] ?? null)) {
        kris_api_respond(['ok' => false, 'error' => 'Sessione scaduta: ricarica la pagina e riprova. Nulla è stato modificato.', 'csrf' => kris_csrf_token()], 419);
    }
}

$str = fn(string $key, string $default = ''): string => is_scalar($body[$key] ?? null) ? (string) $body[$key] : $default;
$writeGuard = function () use ($editorLock): void {
    if ($editorLock !== '') throw new ApiError($editorLock . ' Nulla è stato modificato.', 423);
};
$liveOnly = function (string $what) use ($stagingMode): void {
    if ($stagingMode) throw new ApiError("{$what} si cambia sul sito pubblico: passa a «Sito pubblico».", 409);
};
$adminOnly = function (): void {
    if (!kris_is_admin()) throw new ApiError('Solo un amministratore gestisce gli utenti.', 403);
};
$me = (string) $_SESSION['kris_user'];

/** Lo stato delle modifiche in attesa, per la barra e per «Da pubblicare». */
$stagingSummary = function (bool $full) use (&$stagingMode): array {
    $s = new Staging();
    $manifest = $s->manifest();
    $count = count($s->changes()) + count($s->operations()) + count($manifest['uploads']) + count($manifest['fonts']);
    $out = ['count' => $count, 'visible' => $count > 0 || $s->lastPromotion() !== null];
    if (!$full) return $out;
    $changes = $s->changes();
    $operations = $s->rebuild();
    $siteRoot = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    $last = $s->lastPromotion();
    return $out + [
        'created_by' => (string) ($manifest['created_by'] ?? ''),
        'created_at' => (string) ($manifest['created_at'] ?? ''),
        'operations' => array_map(fn($o) => [
            'description' => $o['description'], 'conflict' => $o['conflict'],
            'by' => (string) ($o['op']['by'] ?? ''), 'at' => (string) ($o['op']['at'] ?? ''),
            'value' => ($o['op']['op'] ?? '') === 'set_field' && is_string($o['op']['value'] ?? null) ? mb_strimwidth(trim(strip_tags($o['op']['value'])), 0, 160, '…') : null,
        ], $operations),
        'files' => array_map(fn($c) => [
            'file' => $c['file'], 'kind' => $c['kind'], 'conflict' => $c['conflict'], 'sensitive' => $c['sensitive'],
            'by' => $c['changed_by'], 'at' => $c['changed_at'], 'diff' => \Kris\Staging\Diff::lines($c['site'] ?? '', $c['staged']),
        ], $changes),
        'uploads' => array_map(fn($name, $info) => ['name' => (string) $name, 'original' => (string) ($info['original'] ?? $name),
            'src' => $siteRoot . '/' . \Kris\Staging\Preview::assetUrl('uploads/' . rawurlencode((string) $name))], array_keys($manifest['uploads']), $manifest['uploads']),
        'fonts' => array_map(fn($name, $info) => ['name' => (string) $name, 'by' => (string) ($info['by'] ?? '')], array_keys($manifest['fonts']), $manifest['fonts']),
        'problems' => $s->templateProblems(),
        'warnings' => $s->warnings(),
        'code' => ['files' => $s->codeFiles(), 'approved' => $s->codeApproved(), 'fingerprint' => (string) $s->codeFingerprint()],
        'preview_pages' => array_map(fn($p) => ['label' => $p['label'], 'url' => $siteRoot . '/' . $p['url']], $s->previewPages()),
        'last_promotion' => $last === null ? null : [
            'at' => (string) $last['promoted_at'], 'by' => (string) $last['promoted_by'],
            'files' => array_merge(array_keys($last['files']), array_map([\Kris\Store\FileStore::class, 'relativePath'], array_keys(is_array($last['documents'] ?? null) ? $last['documents'] : []))),
        ],
        'backup_days' => Staging::BACKUP_DAYS,
    ];
};

/** I file della libreria, i più recenti prima; in attesa anche quelli non ancora pubblici. */
$mediaList = function () use ($uploadDir, $uploadUrl, &$stagingMode): array {
    $staging = new Staging();
    $files = editorMediaFiles($uploadDir);
    $staged = [];
    foreach ($staging->uploadFiles() as $file) {
        if (!is_file($uploadDir . basename($file))) $staged[basename($file)] = $file;
    }
    $out = [];
    foreach ([...array_values($staged), ...$files] as $path) {
        $name = basename($path);
        $url = $uploadUrl . rawurlencode($name);
        $out[] = ['name' => $name, 'url' => $url, 'src' => editorMediaSource($url), 'kind' => editorMediaKind($name),
            'ext' => strtoupper(pathinfo($name, PATHINFO_EXTENSION)), 'size' => (int) @filesize($path), 'mtime' => (int) @filemtime($path),
            'staged' => isset($staged[$name]), 'variants' => MediaStore::isVariant($name) ? [] : array_values(array_map('basename', glob($uploadDir . pathinfo($name, PATHINFO_FILENAME) . '-*w.webp') ?: []))];
    }
    return $out;
};

try {
    switch ($apiName) {
        // --- sessione e dati --------------------------------------------------
        case 'session':
            $user = kris_current_user();
            $maint = $maintenance;
            kris_api_respond(['ok' => true,
                'user' => $user, 'csrf' => kris_csrf_token(), 'version' => $krisVersion,
                'mode' => $stagingMode ? 'staging' : 'live',
                'languages' => editorLanguages(), 'language_names' => $DEFAULT_LANGS,
                'site_name' => editorText(editorItem('kris_site', 0)['values']['site_name'] ?? ''),
                'lock' => $editorLock === '' ? null : ['message' => $editorLock, 'migrations' => $migrationsPending, 'stale' => !empty($maint['stale'])],
                'notice' => $frameworkNotice,
                'update' => $updateHint,
                'wip' => (editorItem('kris_wip', 0)['values']['enabled'] ?? false) === true,
                'email_missing' => !\Kris\Auth\Users::validAddress((string) ($user['email'] ?? '')) && empty($_SESSION['kris_email_later']),
                'staging' => $stagingSummary(false),
                'staged_uploads' => array_keys(editorStagedUploads()),
                'site_url' => rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\') . '/',
                'uploads' => ['accept' => MediaStore::allowedExtensions(), 'max_bytes' => MediaStore::maxBytes(), 'video_max_bytes' => MediaStore::videoMaxBytes(),
                    'can_process' => MediaStore::canProcessImages(), 'can_webp' => MediaStore::canWriteWebp()],
                'field_types' => EDITOR_FIELD_TYPES,
            ]);

        case 'site':
            kris_api_respond(['ok' => true, 'mode' => $stagingMode ? 'staging' : 'live'] + editorSite());

        case 'mode':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $_SESSION['kris_editing'] = $str('mode') === 'staging' ? 'staging' : 'live';
            kris_api_respond(['ok' => true, 'mode' => $_SESSION['kris_editing']]);

        // --- contenuti e struttura ------------------------------------------
        case 'ops':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $ops = $body['ops'] ?? null;
            if (!is_array($ops) || !array_is_list($ops) || !$ops || count($ops) > 200) throw new ApiError('Servono da 1 a 200 operazioni.');
            foreach ($ops as $op) {
                if (!is_array($op)) throw new ApiError('Operazione non valida.');
                $target = (string) ($op['key'] ?? $op['collection'] ?? $op['name'] ?? '');
                if (str_starts_with($target, 'plg_')) throw new ApiError('I dati dei plugin si cambiano dalle loro schermate.', 403);
                if (in_array($op['op'] ?? '', KRIS_API_SCHEMA_OPS, true)) $liveOnly('La struttura');
                if ($stagingMode && $target === 'kris_legal') throw new ApiError('Privacy e cookie si modificano sul sito pubblico: passa a «Sito pubblico».', 409);
            }
            $done = editorApply($ops, $stagingMode);
            kris_api_respond(['ok' => true, 'done' => $done, 'warnings' => editorWarnings(), 'staging' => $stagingSummary(false)]);

        case 'structure':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $liveOnly('La struttura');
            $type = $str('type');
            if (editorType($type) === null || isReservedCollection($type)) throw new ApiError('Questa raccolta non c\'è più.', 404);
            $new = editorCleanSchema(is_array($body['fields'] ?? null) ? $body['fields'] : []);
            if ($problems = validateSchema($new)) throw new ApiError('Struttura non salvata: ' . implode(' ', $problems), 422, $problems);
            $old = editorFields($type);
            if (empty($body['confirm']) && ($impact = editorSchemaImpact($type, $old, $new))) {
                kris_api_respond(['ok' => false, 'error' => 'Queste modifiche cancellano testi già scritti.', 'impact' => $impact], 409);
            }
            $ops = editorSchemaOps($type, $old, $new);
            editorApply($ops, false);
            kris_api_respond(['ok' => true, 'changed' => count($ops)]);

        // --- impostazioni del sito ---------------------------------------
        case 'languages':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $liveOnly('Le lingue');
            $chosen = array_values(array_intersect(array_keys($DEFAULT_LANGS), array_map('strval', (array) ($body['languages'] ?? []))));
            if (!$chosen) throw new ApiError('Scegli almeno una lingua.');
            $main = $str('main');
            if (in_array($main, $chosen, true)) $chosen = [$main, ...array_values(array_diff($chosen, [$main]))];
            \Kris\Store\Stores::site()->transaction(['settings'], fn(array $d): array => ['settings' => ['languages' => $chosen] + $d['settings']]);
            editorReload($stagingMode);
            kris_api_respond(['ok' => true, 'languages' => $chosen]);

        case 'upload-settings':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $uploads = [
                'prefix' => MediaStore::slug(mb_substr($str('prefix'), 0, 60)),
                'max_width' => max(0, min(10000, (int) ($body['max_width'] ?? MediaStore::DEFAULT_MAX_WIDTH))),
                'webp' => !empty($body['webp']) && MediaStore::canWriteWebp(),
            ];
            \Kris\Store\Stores::site()->transaction(['settings'], fn(array $d): array => ['settings' => ['uploads' => $uploads] + $d['settings']]);
            editorReload($stagingMode);
            kris_api_respond(['ok' => true, 'uploads' => $uploads]);

        // --- indirizzi -------------------------------------------------------------
        case 'pages':
            $templates = array_map(fn($f) => basename($f, '.html'), glob(KRIS_ROOT . '/template/*.html') ?: []);
            kris_api_respond(['ok' => true, 'templates' => array_values($templates),
                'seo' => is_array(editorSite()['settings']['seo'] ?? null) ? editorSite()['settings']['seo'] : (object) [],
                'redirects' => is_array(editorSite()['schema']['redirects'] ?? null) ? editorSite()['schema']['redirects'] : (object) []]);

        case 'seo':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $liveOnly('Gli indirizzi');
            $in = is_array($body['seo'] ?? null) ? $body['seo'] : [];
            $base = trim((string) ($in['base_url'] ?? ''));
            if ($base !== '' && preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/[^\s?#]*)?$#i', $base) !== 1) throw new ApiError('L\'indirizzo del sito deve essere completo, per esempio https://www.esempio.it.');
            $robots = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($in['robots'] ?? '')) ?: []), fn($l) => $l !== '' && preg_match('/^[A-Za-z-]+\s*:/', $l) === 1));
            \Kris\Store\Stores::site()->transaction(['settings'], function (array $d) use ($in, $base, $robots): array {
                // Le altre opzioni SEO del sito restano; quelle del modulo si sostituiscono
                $seo = ['pretty_urls' => !empty($in['pretty_urls']), 'auto_language' => !empty($in['auto_language']), 'head' => ($in['head'] ?? true) !== false]
                    + array_diff_key(is_array($d['settings']['seo'] ?? null) ? $d['settings']['seo'] : [], array_flip(['base_url', 'robots']));
                if ($base !== '') $seo['base_url'] = rtrim($base, '/');
                if ($robots) $seo['robots'] = $robots;
                return ['settings' => ['seo' => $seo] + $d['settings']];
            });
            editorReload($stagingMode);
            kris_api_respond(['ok' => true]);

        case 'redirects':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $liveOnly('Gli indirizzi');
            $redirects = [];
            foreach ((array) ($body['redirects'] ?? []) as $from => $to) {
                $from = '/' . ltrim(trim((string) $from), '/');
                $to = trim((string) $to);
                if ($from === '/' || preg_match('#^/[^\s?#]*$#', $from) !== 1) throw new ApiError("«{$from}» non è un indirizzo del sito: inizia con / ed è senza spazi.");
                if (preg_match('#^(/[^\s]*|https://[^\s]+)$#', $to) !== 1) throw new ApiError("«{$to}» non va bene come destinazione: un indirizzo del sito (/…) o https://….");
                $redirects[$from] = $to;
            }
            \Kris\Store\Stores::site()->transaction(['schema'], function (array $d) use ($redirects): array {
                $schema = $d['schema'];
                if ($redirects) $schema['redirects'] = $redirects;
                else unset($schema['redirects']);
                return ['schema' => $schema];
            });
            editorReload($stagingMode);
            kris_api_respond(['ok' => true]);

        case 'store':
            kris_api_respond(['ok' => true, 'driver' => \Kris\Store\Stores::driver(KRIS_ROOT), 'contents' => count(editorSite()['content'])]);

        // --- libreria media ---------------------------------------------------
        case 'media':
            kris_api_respond(['ok' => true, 'files' => $mediaList()]);

        case 'media-delete':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $name = basename($str('name'));
            if ($stagingMode || !is_file($uploadDir . $name)) {
                // Un file ancora in attesa si toglie dallo staging; uno pubblico solo dal sito pubblico
                $staging = new Staging();
                if (!$staging->isStagedUpload($uploadUrl . $name)) $liveOnly('L\'eliminazione di un file');
                $staging->deleteUpload($uploadUrl . $name, 'editor: ' . $me);
            } elseif (!MediaStore::deleteWithVariants($uploadDir, $name)) {
                throw new ApiError('Non è stato possibile eliminare il file. Riprova.');
            }
            kris_api_respond(['ok' => true]);

        case 'media-optimize':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $start = microtime(true);
            $made = 0;
            $left = 0;
            foreach (editorMediaFiles($uploadDir) as $file) {
                if (microtime(true) - $start > 20) { $left++; continue; }
                $made += MediaStore::makeVariants($uploadDir, basename($file));
            }
            kris_api_respond(['ok' => true, 'made' => $made, 'left' => $left]);

        // --- da pubblicare ------------------------------------------------------
        case 'staging':
            kris_api_respond(['ok' => true] + $stagingSummary(true));

        case 'staging-action':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $writeGuard();
            $staging = new Staging();
            $action = $str('action');
            $result = [];
            if ($action === 'approve') {
                $staging->approveCode($str('fingerprint'), $me);
            } elseif ($action === 'undo') {
                $result['restored'] = count($staging->undoLastPromotion());
            } elseif ($action === 'promote' || $action === 'discard') {
                if ($action === 'promote') $result['files'] = count($staging->promote($me, !empty($body['force']))['files']);
                else $staging->discard();
                // Non c'è più niente in attesa: si torna al sito pubblico
                $_SESSION['kris_editing'] = 'live';
                $stagingMode = false;
            } else {
                throw new ApiError('Azione sconosciuta.', 400);
            }
            editorReload($stagingMode);
            kris_api_respond(['ok' => true, 'mode' => $stagingMode ? 'staging' : 'live'] + $result);

        // --- account e utenti --------------------------------------------------
        case 'account':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $action = $str('action');
            if ($action === 'later') {
                $_SESSION['kris_email_later'] = true;
                kris_api_respond(['ok' => true]);
            }
            if ($action === 'test-email') {
                $email = (string) (kris_current_user()['email'] ?? '');
                if (!kris_send_mail($email, 'Email di prova dall\'editor', "Ciao,\n\nse leggi questo messaggio l'hosting invia correttamente le email del sito:\npotrai recuperare la password dell'editor se la dimentichi.\n")) {
                    throw new ApiError('L\'hosting non ha accettato l\'invio: il recupero della password via email non funzionerà. ' . KRIS_HOSTING_HELP);
                }
                kris_api_respond(['ok' => true, 'message' => "Email di prova inviata a {$email}. Se non arriva entro qualche minuto (controlla lo spam), il recupero via email non funzionerà su questo hosting."]);
            }
            // Email e password richiedono la password attuale: l'email riceve i link di reset
            if (kris_users()->check($me, $str('current_password')) !== 'ok') throw new ApiError('La password attuale non è corretta: nulla è stato modificato.');
            if ($action === 'email') {
                kris_users()->setEmail($me, $str('email'));
                kris_users()->setEditorUrl(kris_current_editor_url());
                kris_api_respond(['ok' => true, 'user' => kris_current_user()]);
            }
            if ($action === 'password') {
                if ($str('password') !== $str('password2')) throw new ApiError('Le due password non coincidono.');
                kris_users()->setPassword($me, $str('password'));
                // Le altre sessioni si chiudono, questa resta aperta
                kris_start_session($me);
                kris_api_respond(['ok' => true, 'csrf' => kris_csrf_token()]);
            }
            throw new ApiError('Azione sconosciuta.', 400);

        case 'users':
            $adminOnly();
            kris_api_respond(['ok' => true, 'users' => array_values(kris_users()->all()), 'me' => $me]);

        case 'user':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $adminOnly();
            $action = $str('action');
            $name = strtolower($str('name'));
            if ($name === $me && in_array($action, ['block', 'delete', 'role'], true)) {
                throw new ApiError('Non puoi bloccare, eliminare o cambiare ruolo al tuo utente: chiedilo a un altro amministratore.', 409);
            }
            match ($action) {
                'create' => kris_users()->create($name, $str('password'), $str('email'), $str('role', 'base')),
                'block' => kris_users()->setBlocked($name, true),
                'unblock' => kris_users()->setBlocked($name, false),
                'delete' => kris_users()->delete($name),
                'role' => kris_users()->setRole($name, $str('role')),
                'password' => kris_users()->setPassword($name, $str('password')),
                'email' => kris_users()->setEmail($name, $str('email')),
                default => throw new ApiError('Azione sconosciuta.', 400),
            };
            kris_api_respond(['ok' => true, 'users' => array_values(kris_users()->all())]);

        // --- Kris Agent ----------------------------------------------------------
        case 'agent':
            $config = (new \Kris\Agent\AgentConfig())->load();
            $users = null;
            $usersError = '';
            if ($config !== null) {
                try {
                    $users = (new \Kris\Agent\WorkerClient(new \Kris\Agent\AgentConfig()))->users();
                } catch (\Kris\Agent\AgentException $e) {
                    $usersError = $e->getMessage();
                }
            }
            kris_api_respond(['ok' => true, 'paired' => $config !== null, 'enabled' => !empty($config['enabled']),
                'worker_url' => (string) ($config['worker_url'] ?? ''), 'paired_at' => (string) ($config['paired_at'] ?? ''),
                'site_url' => (string) preg_replace('#/(?:kris-admin|editor)(/index\.php)?$#', '', kris_current_editor_url()),
                'users' => $users, 'users_error' => $usersError]);

        case 'agent-action':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            $agentConfig = new \Kris\Agent\AgentConfig();
            $action = $str('action');
            try {
                if ($action === 'pair') {
                    $siteUrl = (string) preg_replace('#/(kris-admin|editor)(/index\.php)?$#', '', kris_current_editor_url());
                    \Kris\Agent\Pairing::pair($agentConfig, $str('code'), $siteUrl, $siteUrl . '/kris-agent.php', $krisVersion, $me);
                    \Kris\Update\Stubs::ensure(KRIS_ROOT);
                } elseif ($action === 'toggle') {
                    $agentConfig->setEnabled(!empty($body['enabled']));
                } elseif ($action === 'unpair') {
                    $agentConfig->delete();
                } elseif ($action === 'grant') {
                    (new \Kris\Agent\WorkerClient($agentConfig))->decide($str('user_id'), $str('status') === 'approved' ? 'approved' : 'revoked');
                } elseif ($action === 'reset') {
                    (new \Kris\Agent\WorkerClient($agentConfig))->resetAccess();
                } else {
                    throw new ApiError('Azione sconosciuta.', 400);
                }
            } catch (\Kris\Agent\AgentException $e) {
                throw new ApiError($e->getMessage());
            }
            kris_api_respond(['ok' => true]);

        // --- versione di Kris ---------------------------------------------------
        case 'version':
            $state = null;
            $stateError = false;
            try {
                $state = \Kris\Update\SiteState::load();
            } catch (StorageException) {
                $stateError = true;
            }
            $updater = new \Kris\Update\Updater();
            kris_api_respond(['ok' => true, 'version' => $krisVersion, 'state_error' => $stateError,
                'data_version' => $state['data_version'] ?? null,
                'last_check' => is_array($state['last_check'] ?? null) ? $state['last_check'] : null,
                'last_update' => $state ? \Kris\Update\SiteState::lastUpdate($state) : null,
                'history' => array_values(array_filter(array_reverse(array_slice(is_array($state['history'] ?? null) ? $state['history'] : [], -5)), 'is_array')),
                'enabled' => $updater->enabled(),
                'maintenance' => $maintenance,
                'rollback' => $updater->enabled() && $maintenance === null ? $updater->availableRollback() : null,
            ]);

        case 'migrate':
            if (!$apiPost) throw new ApiError('Serve un POST.', 405);
            if (!$migrationsPending) kris_api_respond(['ok' => true]);
            $migrator = new \Kris\Update\Migrator(KRIS_ROOT, KRIS_DIR . '/migrations');
            \Kris\Update\SiteState::save($migrator->run(\Kris\Update\SiteState::load()));
            kris_api_respond(['ok' => true]);

        default:
            throw new ApiError('Richiesta sconosciuta.', 404);
    }
} catch (ApiError $e) {
    kris_api_respond(['ok' => false, 'error' => $e->getMessage()] + ($e->details ? ['details' => $e->details] : []), $e->status);
} catch (StagingException $e) {
    kris_api_respond(['ok' => false, 'error' => $e->getMessage()] + ($e->details() ? ['details' => $e->details()] : []), 422);
} catch (UserException $e) {
    kris_api_respond(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (StorageException | StoreException $e) {
    kris_api_respond(['ok' => false, 'error' => 'Non siamo riusciti a salvare: i dati non sono cambiati. (' . $e->getMessage() . ')'], 500);
}

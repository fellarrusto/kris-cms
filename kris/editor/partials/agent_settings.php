<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<?php
$agent = (new \Kris\Agent\AgentConfig())->load();
$agentSiteUrl = preg_replace('#/editor(/index\.php)?$#', '', kris_current_editor_url());
?>
<section class="card" id="kris-agent"><header class="card-header"><span class="collection-icon"><?= uiIcon('structure') ?></span><div><h2>Kris Agent</h2><p>Permette a un assistente AI di preparare modifiche al sito. Le modifiche restano in attesa finché non le pubblichi tu da «Da pubblicare».</p></div></header><div class="card-body">
<?php if ($agent === null): ?>
    <form method="POST" action="?action=settings&amp;tab=agent" class="agent-pair-form">
        <input type="hidden" name="agent_pair" value="1">
        <label for="agent-code">Codice di abbinamento</label>
        <textarea id="agent-code" name="agent_code" rows="3" required placeholder="KRIS1-…" spellcheck="false"></textarea>
        <small>Lo trovi nel pannello di Kris Agent, nella scheda di questo sito. Vale 30 minuti e funziona solo per <strong><?= h($agentSiteUrl) ?></strong>.</small>
        <button class="btn btn-primary">Abbina il sito</button>
    </form>
<?php else: ?>
    <div class="setting-row static"><span><strong>Stato</strong><small><?= !empty($agent['enabled']) ? 'L\'assistente può preparare modifiche.' : 'Spento: le richieste dell\'assistente vengono rifiutate.' ?></small></span><span class="setting-value"><?= !empty($agent['enabled']) ? 'Attivo' : 'Spento' ?></span></div>
    <div class="setting-row static"><span><strong>Abbinato</strong><small><?= h((string) ($agent['worker_url'] ?? '')) ?></small></span><span class="setting-value"><?= h(editorDateTime($agent['paired_at'] ?? '') ?? '') ?></span></div>
    <div class="agent-actions">
        <form method="POST" action="?action=settings&amp;tab=agent"><input type="hidden" name="agent_toggle" value="<?= !empty($agent['enabled']) ? '0' : '1' ?>"><button class="btn btn-white"><?= !empty($agent['enabled']) ? 'Spegni Kris Agent' : 'Riaccendi Kris Agent' ?></button></form>
        <form method="POST" action="?action=settings&amp;tab=agent" data-confirm-title="Scollegare il sito?" data-confirm="Kris Agent non potrà più preparare modifiche. Per ricollegarlo servirà un nuovo codice dal pannello. Le modifiche già in attesa restano." data-confirm-label="Scollega"><input type="hidden" name="agent_unpair" value="1"><button class="btn btn-danger">Scollega</button></form>
    </div>
    <?php
    // L'elenco lo tiene Kris Agent: si chiede al momento, con una richiesta firmata dal sito
    $agentUsers = null;
    $agentUsersError = '';
    try {
        $agentUsers = (new \Kris\Agent\WorkerClient(new \Kris\Agent\AgentConfig()))->users();
    } catch (\Kris\Agent\AgentException $e) {
        $agentUsersError = $e->getMessage();
    }
    $agentReasons = [
        'ok' => 'Può usare l\'assistente', 'platform_admin' => 'Amministratore di Kris Agent: può sempre',
        'grant_pending' => 'Aspetta la tua approvazione', 'grant_revoked' => 'Escluso da te',
        'no_subscription' => 'Senza abbonamento', 'subscription_suspended' => 'Abbonamento sospeso',
        'subscription_not_started' => 'Abbonamento non ancora iniziato', 'subscription_expired' => 'Abbonamento scaduto',
        'site_not_active' => 'Sito non attivo in Kris Agent', 'user_blocked' => 'Utente bloccato',
    ];
    ?>
    <div class="agent-users">
        <h3>Chi può usare l'assistente su questo sito</h3>
        <p>Decidi tu chi entra. Un collaboratore compare qui quando riceve un abbonamento per questo sito.</p>
        <?php if ($agentUsersError !== ''): ?>
            <div class="alert alert-error" role="alert"><?= h($agentUsersError) ?></div>
        <?php elseif (!$agentUsers): ?>
            <p class="agent-empty">Ancora nessuno.</p>
        <?php else: ?>
            <ul class="agent-user-list">
            <?php foreach ($agentUsers as $u): $isAdmin = ($u['reason'] ?? '') === 'platform_admin'; ?>
                <li>
                    <span class="agent-user"><strong><?= h((string) ($u['name'] ?? '')) ?></strong><small><?= h((string) ($u['email'] ?? '')) ?></small></span>
                    <span class="agent-user-state <?= !empty($u['allowed']) ? 'is-allowed' : '' ?>"><?= h($agentReasons[$u['reason'] ?? ''] ?? (string) ($u['reason'] ?? '')) ?></span>
                    <?php if (!$isAdmin): ?>
                        <form method="POST" action="?action=settings&amp;tab=agent">
                            <input type="hidden" name="agent_grant" value="<?= h((string) $u['user_id']) ?>">
                            <?php if (($u['grant_status'] ?? '') === 'approved'): ?>
                                <input type="hidden" name="status" value="revoked"><button class="btn btn-white">Escludi</button>
                            <?php else: ?>
                                <input type="hidden" name="status" value="approved"><button class="btn btn-primary">Approva</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <form method="POST" action="?action=settings&amp;tab=agent" class="agent-reset" data-confirm-title="Reimpostare l'accesso?" data-confirm="Tutti dovranno essere approvati di nuovo prima di usare l'assistente, e le chiavi del collegamento vengono cambiate. Usalo quando un collaboratore smette di lavorare con te." data-confirm-label="Reimposta">
            <input type="hidden" name="agent_reset" value="1"><button class="btn btn-danger">Reimposta l'accesso</button>
            <small>Tutti tornano in attesa della tua approvazione.</small>
        </form>
    </div>
<?php endif; ?>
</div></section>

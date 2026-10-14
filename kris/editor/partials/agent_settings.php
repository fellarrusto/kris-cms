<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } ?>
<?php
$agent = (new \Kris\Agent\AgentConfig())->load();
$agentSiteUrl = preg_replace('#/editor(/index\.php)?$#', '', kris_current_editor_url());
?>
<section class="card" id="kris-agent"><header class="card-header"><span class="collection-icon"><?= uiIcon('structure') ?></span><div><h2>Kris Agent</h2><p>Permette a un assistente AI di preparare modifiche al sito. Le modifiche restano in attesa finché non le pubblichi tu da «Da pubblicare».</p></div></header><div class="card-body">
<?php if ($agent === null): ?>
    <form method="POST" action="?action=settings#kris-agent" class="agent-pair-form">
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
        <form method="POST" action="?action=settings#kris-agent"><input type="hidden" name="agent_toggle" value="<?= !empty($agent['enabled']) ? '0' : '1' ?>"><button class="btn btn-white"><?= !empty($agent['enabled']) ? 'Spegni Kris Agent' : 'Riaccendi Kris Agent' ?></button></form>
        <form method="POST" action="?action=settings#kris-agent" data-confirm-title="Scollegare il sito?" data-confirm="Kris Agent non potrà più preparare modifiche. Per ricollegarlo servirà un nuovo codice dal pannello. Le modifiche già in attesa restano." data-confirm-label="Scollega"><input type="hidden" name="agent_unpair" value="1"><button class="btn btn-danger">Scollega</button></form>
    </div>
<?php endif; ?>
</div></section>

<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno ?>
<div class="container">
    <div class="page-head"><div><p class="kicker">Struttura / <?= h(collectionLabel((string) $group)) ?></p><h1>Prima di salvare</h1><p class="sub">Queste modifiche tolgono o ricreano campi che contengono già dei testi: applicandole, quei testi vengono cancellati.</p></div></div>
    <div class="box" style="box-shadow: inset 0 0 0 1.5px var(--warn-line)">
        <table class="impact-table">
            <thead><tr><th scope="col">Campo</th><th scope="col">Cosa succede</th><th scope="col">Cosa si perde</th></tr></thead>
            <tbody>
            <?php foreach ($pendingImpact as $row): ?>
                <tr>
                    <td class="mono"><?= h($row['path']) ?></td>
                    <td class="reason"><?= h($row['reason']) ?></td>
                    <td><strong><?= (int) $row['count'] ?></strong> <?= $row['count'] === 1 ? 'contenuto compilato' : 'contenuti compilati' ?>
                        <div class="muted"><?php foreach ($row['samples'] as $sample): ?>«<?= h(mb_strimwidth((string) $sample, 0, 80, '…')) ?>»<br><?php endforeach; ?><?php if ($row['count'] > count($row['samples'])): ?>e altri <?= (int) $row['count'] - count($row['samples']) ?>…<?php endif; ?></div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="hint">Prima di ogni scrittura una copia dei contenuti resta in <code>data/backups/</code>.</p>
        <div class="bar" style="margin-top:16px">
            <a href="?action=structure&amp;group=<?= urlencode((string) $group) ?>" class="btn btn-white">Torna alla struttura</a>
            <form method="POST" data-confirm-title="Applicare le modifiche?" data-confirm="I testi elencati verranno cancellati." data-confirm-label="Applica">
                <input type="hidden" name="save_structure" value="1"><input type="hidden" name="confirm_impact" value="1">
                <input type="hidden" name="group_name" value="<?= h((string) $group) ?>">
                <input type="hidden" name="schema_json" value="<?= h($pendingSchema) ?>">
                <button class="btn btn-danger">Applica e cancella</button>
            </form>
        </div>
    </div>
</div>

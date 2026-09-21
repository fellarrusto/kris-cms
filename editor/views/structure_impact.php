<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno ?>
    <div class="container">
        <h1>
            <span>Controlla le modifiche alla struttura</span>
            <a href="?action=structure&group=<?= urlencode((string) $group) ?>" class="btn btn-white">← Torna alla struttura</a>
        </h1>

        <div class="card">
            <div class="card-body">
                <p style="margin-top:0; color:#4b5563; line-height:1.6;">
                    Le modifiche che hai fatto tolgono o cambiano dei campi che
                    <strong>contengono già dei testi</strong>. Se applichi, al prossimo
                    salvataggio di ogni contenuto quei testi verranno eliminati
                    dall'archivio.
                </p>

                <table>
                    <thead>
                        <tr>
                            <th width="220">Campo</th>
                            <th width="200">Cosa succede</th>
                            <th>Contenuti interessati</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pendingImpact as $row): ?>
                            <tr>
                                <td style="font-family:monospace; font-size:.85rem;"><?= htmlspecialchars($row['path']) ?></td>
                                <td style="color:var(--danger);"><?= htmlspecialchars($row['reason']) ?></td>
                                <td>
                                    <strong><?= (int) $row['count'] ?></strong>
                                    <?php if ($row['type'] === 'array'): ?>
                                        element<?= $row['count'] === 1 ? 'o' : 'i' ?> della lista
                                    <?php else: ?>
                                        <?= $row['count'] === 1 ? 'valore compilato' : 'valori compilati' ?>
                                    <?php endif; ?>
                                    <div style="color:#6b7280; font-size:.82rem; margin-top:4px;">
                                        <?php foreach ($row['samples'] as $sample): ?>
                                            «<?= htmlspecialchars($sample) ?>»<br>
                                        <?php endforeach; ?>
                                        <?php if ($row['count'] > count($row['samples'])): ?>
                                            e altri <?= (int) $row['count'] - count($row['samples']) ?>…
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="color:#6b7280; font-size:.9rem; margin-bottom:0;">
                    Una copia dei contenuti attuali viene comunque salvata in
                    <code>data/backups/</code> prima di ogni modifica.
                </p>
            </div>

            <div class="modal-footer" style="background:#f9fafb; border-top:1px solid var(--border);">
                <a href="?action=structure&group=<?= urlencode((string) $group) ?>" class="btn btn-white">Annulla</a>
                <form method="POST" style="margin:0;"
                    data-confirm-title="Applicare le modifiche?" data-confirm="I dati elencati verranno rimossi al prossimo salvataggio dei contenuti. Controlla il riepilogo prima di continuare." data-confirm-label="Applica modifiche">
                    <input type="hidden" name="save_structure" value="1">
                    <input type="hidden" name="confirm_impact" value="1">
                    <input type="hidden" name="group_name" value="<?= htmlspecialchars((string) $group) ?>">
                    <input type="hidden" name="schema_json" value="<?= htmlspecialchars($pendingSchema) ?>">
                    <button class="btn btn-primary" style="background:var(--danger);">Applica modifiche</button>
                </form>
            </div>
        </div>
    </div>

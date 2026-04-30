<div class="container">
    <h1>
        <?= ucfirst($group) ?>
        <div class="actions">
            <a href="?action=structure&group=<?= $group ?>" class="btn btn-white">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                </svg>
                Struttura
            </a>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="group" value="<?= $group ?>">
                <button name="create_instance" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="12" y1="5" x2="12" y2="19"></line>
                        <line x1="5" y1="12" x2="19" y2="12"></line>
                    </svg>
                    Nuovo Elemento
                </button>
            </form>
        </div>
    </h1>

    <div class="card">
        <?php if (empty($models[$group])): ?>
            <div style="padding:40px; text-align:center; color:#6b7280;">
                <p style="margin-bottom:15px;">Non hai ancora definito i campi per questa collezione.</p>
                <a href="?action=structure&group=<?= $group ?>" class="btn btn-primary">Definisci Struttura</a>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th width="60">ID</th>
                        <th>Anteprima Contenuto</th>
                        <th width="140" style="text-align:right">Azioni</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $list = array_filter($data, fn($i) => $i['name'] === $group);
                    if (empty($list)): ?>
                        <tr>
                            <td colspan="3" style="text-align:center; padding:30px; color:#9ca3af;">Nessun elemento presente.</td>
                        </tr>
                    <?php else:
                        foreach ($list as $item):
                            $prev = '<em style="color:#9ca3af">Vuoto</em>';
                            foreach ($item['data'] as $d) {
                                if (in_array($d['type'], ['text', 'plain', 'richtext'])) {
                                    $v = is_array($d['value']) ? reset($d['value']) : $d['value'];
                                    if ($v) { $prev = mb_substr(strip_tags($v), 0, 70) . '...'; break; }
                                }
                            }
                            ?>
                            <tr>
                                <td><span class="badge">#<?= $item['id'] ?></span></td>
                                <td><?= $prev ?></td>
                                <td style="text-align:right;">
                                    <a href="?action=edit&group=<?= $group ?>&id=<?= $item['id'] ?>" class="btn btn-white" style="padding:6px 10px;">Edit</a>
                                    <form method="POST" style="display:inline"
                                        onsubmit="return confirm('Eliminare definitivamente questo elemento?');">
                                        <input type="hidden" name="group" value="<?= $group ?>">
                                        <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                        <button name="delete_instance" class="btn btn-white"
                                            style="padding:6px 10px; color:var(--danger); border-color:var(--border);">✕</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

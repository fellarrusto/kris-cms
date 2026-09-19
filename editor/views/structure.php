<?php if (!defined('KRIS_EDITOR')) { http_response_code(404); exit; } // file interno ?>
    <div class="container">
        <h1>
            <span>Struttura: <?= htmlspecialchars(ucfirst((string)$group)) ?></span>
            <a href="?action=list&group=<?= urlencode((string)$group) ?>" class="btn btn-white">← Torna ai Dati</a>
        </h1>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="structureForm">
                    <input type="hidden" name="save_structure" value="1">
                    <input type="hidden" name="group_name" value="<?= htmlspecialchars((string)$group) ?>">
                    <input type="hidden" name="schema_json" id="schema_json">

                    <div id="root-schema" class="sf-container">
                        <?php renderSchemaFields($models[$group] ?? []); ?>
                    </div>
                    <button type="button" class="btn btn-white" style="margin-top:8px;"
                        onclick="sfAddField(document.getElementById('root-schema'))">+ Campo</button>

                    <div style="display:flex; justify-content:space-between; border-top:1px solid var(--border); padding-top:20px; margin-top:20px;">
                        <button type="submit" name="delete_collection" class="btn btn-white"
                            style="color:var(--danger);"
                            onclick="return confirm('ATTENZIONE: Stai per eliminare l\'intera collezione e la sua struttura. Continuare?')">Elimina Collezione</button>
                        <button type="submit" class="btn btn-primary">Salva Struttura</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

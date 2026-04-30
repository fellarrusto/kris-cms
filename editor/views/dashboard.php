<div class="container">
    <h1>Dashboard</h1>
    <div class="grid">
        <?php foreach ($models as $name => $fields): ?>
            <a href="?action=list&group=<?= $name ?>" class="dash-card">
                <div>
                    <h3><?= ucfirst($name) ?></h3>
                    <p><?= count($fields) ?> campi configurati</p>
                </div>
                <div class="count"><?= $counts[$name] ?></div>
            </a>
        <?php endforeach; ?>

        <button class="dash-card dash-add"
            onclick="document.getElementById('createModal').style.display='flex'">
            <svg viewBox="0 0 24 24" width="40" height="40" fill="none" stroke="currentColor" stroke-width="1">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <span style="margin-top:10px; font-weight:500;">Nuova Raccolta</span>
        </button>
    </div>
</div>

<div id="createModal" class="modal-backdrop">
    <form method="POST" class="modal">
        <div class="modal-header">
            <h3>Crea Nuova Collezione</h3>
            <button type="button" class="btn-white"
                onclick="document.getElementById('createModal').style.display='none'"
                style="border:none; padding:5px;">✕</button>
        </div>
        <div class="modal-body">
            <label>Nome della collezione (es. articoli, team, servizi)</label>
            <input type="text" name="collection_name" required placeholder="nome_collezione" autofocus>
            <p style="font-size:0.85rem; color:#6b7280; margin-top:10px;">Verrà creato un nuovo modello vuoto che potrai configurare.</p>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-white"
                onclick="document.getElementById('createModal').style.display='none'">Back</button>
            <button name="create_collection" class="btn btn-primary">Crea Collezione</button>
        </div>
    </form>
</div>

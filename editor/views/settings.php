<div class="container">
    <h1>Impostazioni</h1>
    <form method="POST" class="card">
        <div class="card-body">
            <h3>Lingue supportate</h3>
            <p style="color:#6b7280; margin-bottom:20px;">Seleziona le lingue che vuoi gestire nel CMS.</p>
            <div class="grid">
                <?php foreach ($DEFAULT_LANGS as $code => $label): ?>
                    <label class="card" style="padding:15px; display:flex; align-items:center; gap:10px; cursor:pointer;">
                        <input type="checkbox" name="langs[]" value="<?= $code ?>"
                            <?= in_array($code, $activeLangs) ? 'checked' : '' ?> style="width:auto;">
                        <div>
                            <strong><?= $label ?></strong>
                            <div style="font-size:0.8em; color:#9ca3af;"><?= strtoupper($code) ?></div>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="modal-footer">
            <button name="save_settings" class="btn btn-primary">Salva Impostazioni</button>
        </div>
    </form>
</div>

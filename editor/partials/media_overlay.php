<div id="mediaOverlay" class="modal-backdrop">
    <div class="modal" style="max-width:800px; height:80vh; display:flex; flex-direction:column;">
        <div class="modal-header">
            <h3>Seleziona File</h3>
            <button onclick="document.getElementById('mediaOverlay').style.display='none'" class="btn-white"
                style="border:none;">✕</button>
        </div>
        <div class="modal-body" style="background:#f3f4f6;">
            <div id="mediaGrid" class="grid" style="grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap:15px;">
                <div onclick="document.getElementById('overlayUpload').click()"
                    style="background:white; border-radius:6px; cursor:pointer; border:2px dashed #d1d5db; box-shadow:0 1px 2px rgba(0,0,0,0.1); display:flex; flex-direction:column; align-items:center; justify-content:center; aspect-ratio:1; color:#6b7280;"
                    onmouseover="this.style.borderColor='var(--primary)';this.style.color='var(--primary)'"
                    onmouseout="this.style.borderColor='#d1d5db';this.style.color='#6b7280'">
                    <svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                    <span style="font-size:0.7rem; margin-top:5px;">Carica</span>
                </div>
                <input type="file" id="overlayUpload" style="display:none" accept="image/*" onchange="uploadFromOverlay(this)">
                <?php foreach ($images as $img):
                    $url = $uploadUrl . basename($img); ?>
                    <div onclick="selectMedia('<?= $url ?>')"
                        style="background:white; border-radius:6px; overflow:hidden; cursor:pointer; border:2px solid transparent; box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                        <img src="../<?= $url ?>" style="width:100%; aspect-ratio:1; object-fit:cover;">
                        <div style="padding:5px; font-size:0.7rem; text-align:center; overflow:hidden; white-space:nowrap;">
                            <?= basename($img) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

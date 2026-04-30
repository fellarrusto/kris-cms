<div class="container">
    <h1>Media Library</h1>

    <div class="card" style="margin-bottom:20px;">
        <div class="card-body" style="background:#f9fafb;">
            <form method="POST" enctype="multipart/form-data" action="./upload.php"
                style="display:flex; gap:10px; align-items:center;">
                <input type="file" name="file" style="background:white;" required>
                <button class="btn btn-primary">Carica File</button>
            </form>
        </div>
    </div>

    <div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));">
        <?php foreach ($images as $img):
            $fileName = basename($img);
            $publicUrl = $uploadUrl . $fileName;
        ?>
            <div class="card" style="transition:transform 0.1s; position: relative;">

                <form method="POST" onsubmit="return confirm('Sei sicuro di voler eliminare definitivamente <?= $fileName ?>?')"
                      style="position: absolute; top: 5px; right: 5px; z-index: 10;">
                    <input type="hidden" name="delete_media" value="1">
                    <input type="hidden" name="file_name" value="<?= $fileName ?>">
                    <button type="submit"
                            onclick="event.stopPropagation();"
                            style="background:#ef4444; color:white; border:none; border-radius:50%; width:24px; height:24px; cursor:pointer; display:flex; align-items:center; justify-content:center; font-weight:bold; font-size:12px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                        ✕
                    </button>
                </form>

                <div onclick="prompt('URL da copiare:', '<?= $publicUrl ?>')" style="cursor:pointer;">
                    <div style="aspect-ratio:1; overflow:hidden; border-bottom:1px solid var(--border); background:#eee; display:flex; align-items:center; justify-content:center;">
                        <img src="../<?= $publicUrl ?>" style="width:100%; height:100%; object-fit:cover;">
                    </div>
                    <div style="padding:10px; font-size:0.8rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#374151;">
                        <?= $fileName ?>
                    </div>
                </div>

            </div>
        <?php endforeach; ?>
    </div>
</div>

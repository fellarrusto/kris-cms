// Comportamenti del pannello: media picker, schede lingua, stato
// "modifiche non salvate", riordino drag and drop e avvio di TinyMCE.

let tgt = null;
let tinymceCallback = null;
// Variabile globale per tracciare le modifiche
let hasUnsavedChanges = false;

// --- GESTIONE MODIFICHE ---
function markAsDirty() {
    hasUnsavedChanges = true;
}

function confirmExit(e) {
    if (hasUnsavedChanges) {
        const choice = confirm("Hai modifiche non salvate.\nSe esci ora, andranno perse.\n\nSei sicuro di voler uscire?");
        if (!choice) {
            e.preventDefault(); // Blocca il click
            return false;
        }
    }
    return true; // Procede
}

// --- GESTIONE MEDIA ---
function pickMedia(id) { 
    tgt = id; 
    tinymceCallback = null; 
    document.getElementById('mediaOverlay').style.display = 'flex'; 
}

function openCmsMediaPicker(callback, value, meta) {
    tinymceCallback = callback;
    tgt = null; 
    document.getElementById('mediaOverlay').style.display = 'flex'; 
}

function selectMedia(u) { 
    if(tinymceCallback) {
        tinymceCallback(u, { title: u.split('/').pop() });
        tinymceCallback = null;
        markAsDirty();
    } else if(tgt) {
        document.getElementById(tgt).value = u;
        // Aggiorna preview
        var container = document.getElementById(tgt).closest('.tab-content');
        var preview = container.querySelector('img');
        if (preview) {
            preview.src = '../' + u;
        } else {
            var div = document.createElement('div');
            div.style.cssText = 'margin-top:10px;padding:5px;border:1px solid var(--border);border-radius:6px;display:inline-block;background:white';
            div.innerHTML = '<img src="../' + u + '" style="height:100px;display:block;object-fit:cover">';
            container.appendChild(div);
        }
        markAsDirty();
    }
    document.getElementById('mediaOverlay').style.display = 'none'; 
}

function openTab(el, cid, grp) {
    document.querySelectorAll('.group-' + grp).forEach(x => x.classList.remove('active'));
    document.getElementById(cid).classList.add('active');
    el.parentElement.querySelectorAll('.tab-btn').forEach(x => x.classList.remove('active'));
    el.classList.add('active');
}

function uploadFromOverlay(input) {
    if (!input.files[0]) return;
    const file = input.files[0];
    const form = new FormData();
    form.append('file', file);

    const grid = document.getElementById('mediaGrid');
    const closeBtn = document.querySelector('#mediaOverlay .modal-header button');

    // Mostra busy state: spinner card + dimming del resto + disabilita chiusura
    grid.classList.add('is-uploading');
    const busy = document.createElement('div');
    busy.className = 'upload-busy';
    busy.innerHTML = '<div class="upload-spinner"></div><span>Caricamento…</span><span style="color:#9ca3af; max-width:90%; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">' + file.name + '</span>';
    grid.insertBefore(busy, grid.children[1]); // dopo la card "Carica"
    if (closeBtn) closeBtn.disabled = true;

    const cleanup = () => {
        busy.remove();
        grid.classList.remove('is-uploading');
        if (closeBtn) closeBtn.disabled = false;
        input.value = '';
    };

    form.append('csrf', document.querySelector('meta[name="kris-csrf"]').content);

    fetch('./upload.php', {
            method: 'POST',
            body: form,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(async r => {
            const data = await r.json().catch(() => null);
            if (!r.ok) throw new Error((data && data.error) || ('HTTP ' + r.status));
            return data;
        })
        .then(data => {
            if (!data || !data.url) throw new Error('Risposta non valida');
            const card = document.createElement('div');
            card.onclick = () => selectMedia(data.url);
            card.style.cssText = 'background:white;border-radius:6px;overflow:hidden;cursor:pointer;border:2px solid transparent;box-shadow:0 1px 2px rgba(0,0,0,0.1)';
            card.innerHTML = '<img src="../' + data.url + '" style="width:100%;aspect-ratio:1;object-fit:cover"><div style="padding:5px;font-size:0.7rem;text-align:center;overflow:hidden;white-space:nowrap">' + data.url.split('/').pop() + '</div>';
            grid.children[1].after(card);
            cleanup();
        })
        .catch(err => {
            cleanup();
            alert('Errore durante il caricamento: ' + err.message);
        });
}

// --- DRAG & DROP RIORDINO ---
function initDnd() {
    document.querySelectorAll('table.dnd-table').forEach(table => {
        const tbody = table.tBodies[0];
        if (!tbody) return;
        let dragRow = null;

        tbody.querySelectorAll('tr[draggable="true"]').forEach(row => {
            row.addEventListener('dragstart', e => {
                dragRow = row;
                row.classList.add('dnd-dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', row.dataset.id); } catch(_) {}
            });
            row.addEventListener('dragend', () => {
                row.classList.remove('dnd-dragging');
                tbody.querySelectorAll('tr').forEach(r => r.classList.remove('dnd-over-top','dnd-over-bottom'));
                dragRow = null;
            });
            row.addEventListener('dragover', e => {
                if (!dragRow || dragRow === row) return;
                e.preventDefault();
                const rect = row.getBoundingClientRect();
                const before = (e.clientY - rect.top) < rect.height / 2;
                row.classList.toggle('dnd-over-top', before);
                row.classList.toggle('dnd-over-bottom', !before);
            });
            row.addEventListener('dragleave', () => {
                row.classList.remove('dnd-over-top','dnd-over-bottom');
            });
            row.addEventListener('drop', e => {
                if (!dragRow || dragRow === row) return;
                e.preventDefault();
                const rect = row.getBoundingClientRect();
                const before = (e.clientY - rect.top) < rect.height / 2;
                if (before) tbody.insertBefore(dragRow, row);
                else tbody.insertBefore(dragRow, row.nextSibling);
                row.classList.remove('dnd-over-top','dnd-over-bottom');

                // Submit del form con il nuovo ordine
                const ids = [...tbody.querySelectorAll('tr[draggable="true"]')]
                    .map(r => r.dataset.id).join(',');
                const scope = table.dataset.dndScope;
                let form;
                if (scope === 'root') {
                    form = document.getElementById('reorder_root_form');
                } else if (scope === 'nested') {
                    form = document.getElementById('reorder_nested_' + table.dataset.dndField);
                }
                if (form) {
                    // Il riordino e una richiesta a se: se il form di
                    // modifica ha testo non salvato, inviarla lo
                    // perderebbe. Prima si chiede, e non si azzera il
                    // flag per nascondere l'avviso.
                    if (hasUnsavedChanges && !confirm(
                        'Hai modifiche non salvate in questa pagina.\n' +
                        'Il riordino viene salvato subito, mentre le modifiche ai testi andrebbero perse.\n\n' +
                        'Procedere comunque con il riordino?')) {
                        location.reload();
                        return;
                    }
                    form.querySelector('input[name="order"]').value = ids;
                    hasUnsavedChanges = false;
                    form.submit();
                }
            });
        });
    });
}

// --- INIZIALIZZAZIONE ---
document.addEventListener("DOMContentLoaded", function() {
    initDnd();

    // 1. Rileva modifiche su input normali (text, textarea)
    document.querySelectorAll('form.card input, form.card textarea, form.card select').forEach(el => {
        el.addEventListener('input', markAsDirty);
        el.addEventListener('change', markAsDirty);
    });

    // 2. Reset dirty flag al submit del form (salvataggio reale)
    const form = document.querySelector('form.card');
    if (form) form.addEventListener('submit', () => { hasUnsavedChanges = false; });

    // 3. Dialog nativo solo su refresh/chiusura tab — non blocca ogni click nella pagina
    window.addEventListener('beforeunload', function(e) {
        if (hasUnsavedChanges) e.preventDefault();
    });

    // 4. Inizializza TinyMCE
    if (document.querySelector('.richtext')) {
        tinymce.init({
            selector: '.richtext',
            height: 400,
            menubar: false,
            plugins: 'image link lists code fullscreen',
            toolbar: 'undo redo | blocks | bold italic | alignleft aligncenter alignright | bullist numlist | link image | code fullscreen',
            file_picker_callback: openCmsMediaPicker,
            content_style: 'body { font-family:Segoe UI,Arial,sans-serif; font-size:14px }',
            setup: function(editor) {
                // isReady evita che gli eventi di init di TinyMCE marchino dirty
                let isReady = false;
                editor.on('init', function() {
                    setTimeout(() => { isReady = true; }, 300);
                });
                editor.on('change', function() { if (isReady) markAsDirty(); });
                editor.on('keyup',  function() { if (isReady) markAsDirty(); });
            }
        });
    }
});

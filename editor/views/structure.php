<div class="container">
    <h1>
        <span>Struttura: <?= ucfirst($group) ?></span>
        <a href="?action=list&group=<?= $group ?>" class="btn btn-white">← Torna ai Dati</a>
    </h1>

    <div class="card">
        <div class="card-body">
            <form method="POST" id="structureForm">
                <input type="hidden" name="save_structure" value="1">
                <input type="hidden" name="group_name" value="<?= $group ?>">
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

<style>
    .sf-container { display:flex; flex-direction:column; gap:6px; }
    .sf-row { background:#f9fafb; border:1px solid var(--border); border-radius:6px; padding:10px; }
    .sf-header { display:flex; gap:8px; align-items:center; }
    .sf-header .sf-name { flex:1; min-width:0; }
    .sf-header .sf-type { width:200px; flex-shrink:0; }
    .sf-nested {
        margin-top:10px; padding:10px; background:#fff;
        border-left:3px solid var(--primary, #3b82f6);
        border-radius:0 4px 4px 0;
        display:flex; flex-direction:column; gap:6px;
    }
    .sf-add-child { align-self:flex-start; font-size:0.85rem; padding:4px 10px; }
</style>

<script>
    const SF_TYPES = [
        ['text',     'Testo Multilingua'],
        ['richtext', 'Richtext'],
        ['image',    'Media / File'],
        ['plain',    'Testo Semplice'],
        ['array',    'Array (lista innestata)'],
    ];

    function sfTypeChange(select) {
        const nested = select.closest('.sf-row').querySelector('.sf-nested');
        nested.style.display = select.value === 'array' ? 'flex' : 'none';
    }

    function sfAddField(container) {
        const typeOptions = SF_TYPES.map(([v, l]) => `<option value="${v}">${l}</option>`).join('');
        const row = document.createElement('div');
        row.className = 'sf-row';
        row.innerHTML = `
            <div class="sf-header">
                <input type="text" class="sf-name" placeholder="Nome campo (es. title)">
                <select class="sf-type" onchange="sfTypeChange(this)">${typeOptions}</select>
                <button type="button" class="btn btn-white sf-remove" style="color:var(--danger);">✕</button>
            </div>
            <div class="sf-nested" style="display:none">
                <button type="button" class="btn btn-white sf-add-child"
                    onclick="sfAddField(this.closest('.sf-nested'))">+ Sotto-campo</button>
            </div>`;
        row.querySelector('.sf-remove').addEventListener('click', () => row.remove());
        const addBtn = [...container.children].find(c => c.tagName === 'BUTTON' && c.classList.contains('sf-add-child'));
        container.insertBefore(row, addBtn ?? null);
    }

    function sfSerialize(container) {
        return [...container.children]
            .filter(c => c.classList.contains('sf-row'))
            .map(row => {
                const name = row.querySelector('.sf-name').value
                    .trim().toLowerCase().replace(/[^a-z0-9_]/g, '');
                const type = row.querySelector('.sf-type').value;
                if (!name) return null;
                const entry = { name, type };
                if (type === 'array') entry.of = sfSerialize(row.querySelector('.sf-nested'));
                return entry;
            })
            .filter(Boolean);
    }

    document.querySelectorAll('.sf-remove').forEach(btn => {
        btn.addEventListener('click', () => btn.closest('.sf-row').remove());
    });

    document.getElementById('structureForm').addEventListener('submit', function() {
        document.getElementById('schema_json').value =
            JSON.stringify(sfSerialize(document.getElementById('root-schema')));
    });
</script>

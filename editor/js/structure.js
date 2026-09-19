// Costruttore dello schema: usato solo dalla schermata Struttura.

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
    const typeOptions = SF_TYPES.map(([v, l]) =>
        `<option value="${v}">${l}</option>`).join('');
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
            if (type === 'array') {
                entry.of = sfSerialize(row.querySelector('.sf-nested'));
            }
            return entry;
        })
        .filter(Boolean);
}

// Wire remove buttons on server-rendered rows
document.querySelectorAll('.sf-remove').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('.sf-row').remove());
});

document.getElementById('structureForm').addEventListener('submit', function() {
    document.getElementById('schema_json').value =
        JSON.stringify(sfSerialize(document.getElementById('root-schema')));
});

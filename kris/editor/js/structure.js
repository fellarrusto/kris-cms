// Struttura: la pagina costruisce lo schema e lo manda con un POST normale; le
// modifiche che cancellano testi passano dal riepilogo del server.
'use strict';
const SF_TYPES = [['text','Testo'],['richtext','Testo formattato'],['media','Immagine o file'],['boolean','Interruttore'],['select','Scelta'],['list','Elenco']];
const SF_ICON = name => q(`.sf-${name}`)?.innerHTML || '';
const SF_SCALAR = '<div class="sf-scalar"><label class="check-row"><input type="checkbox" class="sf-localized" checked><span><strong>Diverso per lingua</strong><small>Spento: un solo valore per tutte le lingue (prezzi, codici, indirizzi).</small></span></label><div class="sf-options" hidden><label class="field-label">Opzioni, una per riga<textarea class="input sf-options-list" rows="4"></textarea></label><label class="check-row"><input type="checkbox" class="sf-multiple"><span><strong>Più opzioni insieme</strong><small>Per tag e categorie: nei template si controllano con «has».</small></span></label></div></div>';
const SF_ROOT = () => q('#root-schema');
const sfEscape = text => text.replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function sfTypeChange(select) {
    const row = select.closest('.sf-row');
    const type = select.value;
    const nested = q(':scope > .sf-nested', row);
    nested.hidden = type !== 'list';
    qa('.sf-name', nested).forEach(input => input.required = type === 'list');
    const scalar = q(':scope > .sf-scalar', row);
    scalar.hidden = type === 'list';
    q('.sf-options', scalar).hidden = type !== 'select';
    // Interruttori e scelte di solito valgono per tutte le lingue; i testi no
    if (select.dataset.touched !== '1') q('.sf-localized', scalar).checked = ['text', 'richtext', 'media'].includes(type);
    markAsDirty();
}

// --- Sezioni (solo primo livello) -------------------------------------------
// Ogni riquadro [data-sf-group] è una sezione; "none" raccoglie i campi senza.
function sfGroups() { return qa(':scope > [data-sf-group]', SF_ROOT()); }
function sfGroupName(group) { const input = q(':scope > .sf-group-header .sf-section-name', group); return input ? input.value.trim() : ''; }
function sfRefreshSections() {
    const groups = sfGroups();
    qa(':scope > [data-sf-group] > .sf-group-fields > .sf-row', SF_ROOT()).forEach(row => {
        const select = q(':scope > .sf-section-label .sf-move', row); if (!select) return;
        const current = row.closest('[data-sf-group]').dataset.sfGroup;
        select.innerHTML = groups.map(g => { const key = g.dataset.sfGroup; const name = key === 'none' ? 'Nessuna sezione' : (sfGroupName(g) || 'Sezione senza nome'); return `<option value="${key}" ${key === current ? 'selected' : ''}>${sfEscape(name)}</option>`; }).join('');
    });
    // Una sezione vuota non si salva: il nome serve solo se contiene campi
    groups.forEach(g => { const empty = !q(':scope > .sf-group-fields > .sf-row', g); g.classList.toggle('is-empty', empty); const name = q(':scope > .sf-group-header .sf-section-name', g); if (name) name.required = !empty; });
}
function sfMoveTo(row, key) {
    const group = sfGroups().find(g => g.dataset.sfGroup === key); if (!group) return;
    q(':scope > .sf-group-fields', group).appendChild(row); sfRefreshSections(); markAsDirty();
}
function sfAddSection() {
    const key = 'n' + Date.now().toString(36);
    const group = document.createElement('section'); group.className = 'sf-group sf-section-group is-empty'; group.dataset.sfGroup = key;
    group.innerHTML = '<div class="sf-group-header"><label class="field-label">Nome della sezione<input type="text" class="input sf-section-name" maxlength="60" placeholder="Es. Meta, Contatti"></label><button type="button" class="btn btn-white btn-small sf-section-remove">Togli sezione</button></div><div class="sf-group-fields" data-sf-drop></div><p class="sf-drop-hint">Trascina qui i campi, oppure scegli questa sezione dal menu «Sezione» di un campo. Una sezione vuota non viene salvata.</p>';
    SF_ROOT().appendChild(group); sfRefreshSections(); q('.sf-section-name', group).focus(); markAsDirty();
}
function sfRemoveSection(group) {
    const none = q(':scope > .sf-group-fields', sfGroups().find(g => g.dataset.sfGroup === 'none'));
    qa(':scope > .sf-group-fields > .sf-row', group).forEach(row => none.appendChild(row));
    group.remove(); sfRefreshSections(); markAsDirty(); q('[data-add-section]').focus();
}

function sfAddField(container) {
    const root = container === SF_ROOT();
    const row = document.createElement('div'); row.className = 'sf-row'; row.dataset.depth = root ? '0' : '1';
    const drag = root ? `<button type="button" class="icon-button sf-drag" aria-label="Trascina per spostare il campo in un'altra sezione" title="Trascina">${SF_ICON('drag') || '⋮⋮'}</button>` : '';
    row.innerHTML = `<div class="sf-header">${drag}<input type="text" class="input sf-name" placeholder="nome_tecnico" aria-label="Nome tecnico del campo" required pattern="[a-z][a-z0-9_]*"><select class="input sf-type" aria-label="Tipo del campo">${SF_TYPES.map(([v, l]) => `<option value="${v}">${l}</option>`).join('')}</select><button type="button" class="icon-button danger sf-remove" aria-label="Rimuovi campo">${SF_ICON('remove') || '×'}</button></div><label class="field-label sf-description-label">Descrizione per chi modifica<input type="text" class="input sf-description" placeholder="Es. Titolo in testa alla pagina"></label>${root ? '<label class="field-label sf-section-label">Sezione<select class="input sf-move"></select></label>' : ''}${SF_SCALAR}<div class="sf-nested" hidden><p class="sf-nested-label">Campi di ogni elemento</p><button type="button" class="btn btn-white btn-small sf-add-child">+ Campo dell'elemento</button></div>`;
    if (root) {
        q(':scope > .sf-group-fields', sfGroups().find(g => g.dataset.sfGroup === 'none')).appendChild(row); sfRefreshSections();
    } else {
        container.insertBefore(row, q(':scope > .sf-add-child', container));
    }
    q('.sf-name', row).focus(); markAsDirty();
}
function sfSerializeRows(rows, section) {
    return rows.map(row => {
        const entry = {name: q('.sf-name', row).value.trim(), type: q('.sf-type', row).value, description: q('.sf-description', row).value};
        if (section !== null) entry.section = section;
        if (entry.type === 'list') {
            entry.of = sfSerializeRows(qa(':scope > .sf-nested > .sf-row', row), null);
        } else {
            // Sempre esplicito: una spunta tolta deve arrivare al server
            const scalar = q(':scope > .sf-scalar', row);
            entry.localized = q('.sf-localized', scalar).checked;
            if (entry.type === 'select') { entry.options = q('.sf-options-list', scalar).value.split('\n').map(o => o.trim()).filter(Boolean); entry.multiple = q('.sf-multiple', scalar).checked; }
        }
        return entry;
    });
}
// L'ordine salvato è quello dei riquadri: prima i campi senza sezione, poi una sezione dopo l'altra
function sfSerialize() {
    return sfGroups().flatMap(group => sfSerializeRows(qa(':scope > .sf-group-fields > .sf-row', group), sfGroupName(group)));
}

// --- Trascinamento: dalla maniglia, fra i riquadri del primo livello ---------
let sfDragging = null;
document.addEventListener('pointerdown', event => { const handle = event.target.closest('.sf-drag'); if (handle) handle.closest('.sf-row').draggable = true; });
document.addEventListener('pointerup', () => { if (!sfDragging) qa('.sf-row[draggable="true"]').forEach(row => row.draggable = false); });
document.addEventListener('dragstart', event => {
    const row = event.target.closest?.('.sf-row[draggable="true"]'); if (!row) return;
    sfDragging = row; row.classList.add('sf-dragging'); event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', q('.sf-name', row).value);
});
document.addEventListener('dragover', event => {
    if (!sfDragging) return;
    const zone = event.target.closest('[data-sf-group]')?.querySelector(':scope > [data-sf-drop]'); if (!zone) return;
    event.preventDefault();
    const after = qa(':scope > .sf-row:not(.sf-dragging)', zone).find(row => { const box = row.getBoundingClientRect(); return event.clientY < box.top + box.height / 2; });
    if (after) zone.insertBefore(sfDragging, after); else zone.appendChild(sfDragging);
});
document.addEventListener('drop', event => { if (sfDragging) event.preventDefault(); });
document.addEventListener('dragend', () => {
    if (!sfDragging) return;
    sfDragging.classList.remove('sf-dragging'); sfDragging.draggable = false; sfDragging = null; sfRefreshSections(); markAsDirty();
});

document.addEventListener('click', async event => {
    const button = event.target.closest('button'); if (!button) return;
    if (button.hasAttribute('data-add-root')) sfAddField(SF_ROOT());
    if (button.hasAttribute('data-add-section')) sfAddSection();
    if (button.classList.contains('sf-section-remove')) sfRemoveSection(button.closest('[data-sf-group]'));
    if (button.classList.contains('sf-add-child')) sfAddField(button.closest('.sf-nested'));
    if (button.classList.contains('sf-remove')) {
        const row = button.closest('.sf-row'); const name = q('.sf-name', row).value;
        const yes = await choose('Togliere questo campo?', `Il campo ${name || 'senza nome'} sparisce dalla struttura proposta. Prima di salvare vedrai se ci sono testi che andrebbero persi.`, [{label: 'Tienilo', value: false}, {label: 'Togli campo', value: true, kind: 'btn-danger'}]);
        if (yes) { row.remove(); sfRefreshSections(); markAsDirty(); q('[data-add-root]').focus(); }
    }
});
document.addEventListener('change', event => {
    if (event.target.matches('.sf-type')) sfTypeChange(event.target);
    if (event.target.matches('.sf-localized')) q(':scope > .sf-header .sf-type', event.target.closest('.sf-row')).dataset.touched = '1';
    if (event.target.matches('.sf-move')) { const row = event.target.closest('.sf-row'); sfMoveTo(row, event.target.value); q('.sf-move', row).focus(); }
});
document.addEventListener('input', event => { if (event.target.matches('.sf-section-name')) sfRefreshSections(); });
q('#structureForm')?.addEventListener('submit', () => { q('#schema_json').value = JSON.stringify(sfSerialize()); });
if (SF_ROOT()) sfRefreshSections();

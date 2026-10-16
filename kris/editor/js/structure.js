// Schema changes remain a normal POST: destructive changes go through the server impact view.
'use strict';
const SF_TYPES = [['text','Testo multilingua'],['richtext','Testo formattato'],['image','Media / file'],['plain','Testo condiviso'],['array','Elenco annidato']];
// Il flag posts esiste solo sugli elenchi di primo livello.
const SF_POSTS='<label class="sf-posts-label" hidden><input type="checkbox" class="sf-posts"><span><strong>Mostra in Posts</strong><small>Accesso rapido dalla barra laterale; i nuovi elementi vengono aggiunti in cima.</small></span></label>';
const SF_ROOT=()=>q('#root-schema');
const sfEscape=text=>text.replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
function sfTypeChange(select) {
    const nested=select.closest('.sf-row').querySelector('.sf-nested');
    nested.style.display=select.value==='array'?'flex':'none';
    nested.querySelectorAll('.sf-name').forEach(input=>input.required=select.value==='array');
    const posts=select.closest('.sf-row').querySelector(':scope > .sf-posts-label');if(posts)posts.hidden=select.value!=='array';
    markAsDirty();
}

// --- Sezioni (solo primo livello) -------------------------------------------
// Ogni riquadro [data-sf-group] e una sezione; "none" raccoglie i campi senza.
// Il menu «Sezione» di ogni campo elenca i riquadri e sposta il campo.
function sfGroups(){return qa(':scope > [data-sf-group]',SF_ROOT());}
function sfGroupName(group){const input=q(':scope > .sf-group-header .sf-section-name',group);return input?input.value.trim():'';}
function sfRefreshSections(){
    const groups=sfGroups();
    qa(':scope > [data-sf-group] > .sf-group-fields > .sf-row',SF_ROOT()).forEach(row=>{
        const select=q(':scope > .sf-section-label .sf-move',row);if(!select)return;
        const current=row.closest('[data-sf-group]').dataset.sfGroup;
        select.innerHTML=groups.map(g=>{const key=g.dataset.sfGroup;const name=key==='none'?'Nessuna sezione':(sfGroupName(g)||'Sezione senza nome');return `<option value="${key}" ${key===current?'selected':''}>${sfEscape(name)}</option>`;}).join('');
    });
    // Una sezione vuota non si salva: il nome serve solo se contiene campi
    groups.forEach(g=>{const empty=!q(':scope > .sf-group-fields > .sf-row',g);g.classList.toggle('is-empty',empty);const name=q(':scope > .sf-group-header .sf-section-name',g);if(name)name.required=!empty;});
}
function sfMoveTo(row,key){
    const group=sfGroups().find(g=>g.dataset.sfGroup===key);if(!group)return;
    q(':scope > .sf-group-fields',group).appendChild(row);sfRefreshSections();markAsDirty();
}
function sfAddSection(){
    const key='n'+Date.now().toString(36);
    const group=document.createElement('section');group.className='sf-group sf-section-group is-empty';group.dataset.sfGroup=key;
    group.innerHTML='<div class="sf-group-header"><label>Nome della sezione<input type="text" class="sf-section-name" maxlength="60" placeholder="Es. Meta, Contatti"></label><button type="button" class="btn btn-white sf-section-remove">Togli sezione</button></div><div class="sf-group-fields" data-sf-drop></div><p class="sf-drop-hint">Trascina qui i campi, oppure scegli questa sezione dal menu «Sezione» di un campo. Una sezione vuota non viene salvata.</p>';
    SF_ROOT().appendChild(group);sfRefreshSections();q('.sf-section-name',group).focus();markAsDirty();
}
function sfRemoveSection(group){
    const none=q(':scope > .sf-group-fields',sfGroups().find(g=>g.dataset.sfGroup==='none'));
    qa(':scope > .sf-group-fields > .sf-row',group).forEach(row=>none.appendChild(row));
    group.remove();sfRefreshSections();markAsDirty();q('[data-add-section]').focus();
}

function sfAddField(container) {
    const root=container===SF_ROOT();
    const row=document.createElement('div');row.className='sf-row';row.dataset.depth=root?'0':'1';
    row.innerHTML=`<div class="sf-header">${root?'<button type="button" class="icon-button sf-drag" aria-label="Trascina per spostare il campo in un\'altra sezione" title="Trascina"><span aria-hidden="true">&#x2807;</span></button>':''}<input type="text" class="sf-name" placeholder="Nome tecnico" aria-label="Nome tecnico del campo" required pattern="[a-z0-9_]+"><select class="sf-type" aria-label="Tipo del campo">${SF_TYPES.map(([v,l])=>`<option value="${v}">${l}</option>`).join('')}</select><button type="button" class="icon-button danger sf-remove" aria-label="Rimuovi campo">&times;</button></div><label class="sf-description-label">Descrizione visibile<input type="text" class="sf-description" placeholder="Aiuta chi modifica il contenuto"></label>${root?'<label class="sf-section-label">Sezione<select class="sf-move"></select></label>'+SF_POSTS:''}<div class="sf-nested" style="display:none"><button type="button" class="btn btn-white sf-add-child">+ Sotto-campo</button></div>`;
    if(root){
        q(':scope > .sf-group-fields',sfGroups().find(g=>g.dataset.sfGroup==='none')).appendChild(row);sfRefreshSections();
    }else{
        const add=[...container.children].find(child=>child.classList.contains('sf-add-child'));
        container.insertBefore(row,add||null);
    }
    q('.sf-name',row).focus();markAsDirty();
}
function sfSerializeRows(rows,section) {
    return rows.map(row=>{
        const entry={name:q('.sf-name',row).value.trim(),type:q('.sf-type',row).value,description:q('.sf-description',row).value};
        if(section!==null)entry.section=section;
        if(entry.type==='array')entry.of=sfSerializeRows([...q('.sf-nested',row).children].filter(c=>c.classList.contains('sf-row')),null);
        const posts=row.querySelector(':scope > .sf-posts-label .sf-posts');if(posts)entry.posts=entry.type==='array'&&posts.checked;
        return entry;
    });
}
// L'ordine salvato e quello dei riquadri: prima i campi senza sezione, poi una sezione dopo l'altra.
function sfSerialize() {
    return sfGroups().flatMap(group=>sfSerializeRows(qa(':scope > .sf-group-fields > .sf-row',group),sfGroupName(group)));
}

// --- Trascinamento: dalla maniglia, fra i riquadri del primo livello ---------
let sfDragging=null;
document.addEventListener('pointerdown',event=>{const handle=event.target.closest('.sf-drag');if(handle)handle.closest('.sf-row').draggable=true;});
document.addEventListener('pointerup',()=>{if(!sfDragging)qa('.sf-row[draggable="true"]').forEach(row=>row.draggable=false);});
document.addEventListener('dragstart',event=>{
    const row=event.target.closest?.('.sf-row[draggable="true"]');if(!row)return;
    sfDragging=row;row.classList.add('sf-dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',q('.sf-name',row).value);
});
document.addEventListener('dragover',event=>{
    if(!sfDragging)return;
    const zone=event.target.closest('[data-sf-group]')?.querySelector(':scope > [data-sf-drop]');if(!zone)return;
    event.preventDefault();
    const after=qa(':scope > .sf-row:not(.sf-dragging)',zone).find(row=>{const box=row.getBoundingClientRect();return event.clientY<box.top+box.height/2;});
    if(after)zone.insertBefore(sfDragging,after);else zone.appendChild(sfDragging);
});
document.addEventListener('drop',event=>{if(sfDragging)event.preventDefault();});
document.addEventListener('dragend',()=>{
    if(!sfDragging)return;
    sfDragging.classList.remove('sf-dragging');sfDragging.draggable=false;sfDragging=null;sfRefreshSections();markAsDirty();
});

document.addEventListener('click',async event=>{
    const button=event.target.closest('button');if(!button)return;
    if(button.hasAttribute('data-add-root'))sfAddField(SF_ROOT());
    if(button.hasAttribute('data-add-section'))sfAddSection();
    if(button.classList.contains('sf-section-remove'))sfRemoveSection(button.closest('[data-sf-group]'));
    if(button.classList.contains('sf-add-child'))sfAddField(button.closest('.sf-nested'));
    if(button.classList.contains('sf-remove')){
        const row=button.closest('.sf-row');const name=q('.sf-name',row).value;
        const yes=await choose('Rimuovere questo campo dal modello?',`Il campo ${name||'senza nome'} sarà rimosso dalla struttura proposta. Prima del salvataggio verrà verificato l’impatto sui contenuti esistenti.`,[{label:'Mantieni campo',value:false},{label:'Rimuovi campo',value:true,kind:'btn-danger'}]);
        if(yes){row.remove();sfRefreshSections();markAsDirty();q('[data-add-root]').focus();}
    }
});
document.addEventListener('change',event=>{
    if(event.target.matches('.sf-type'))sfTypeChange(event.target);
    if(event.target.matches('.sf-move')){const row=event.target.closest('.sf-row');sfMoveTo(row,event.target.value);q('.sf-move',row).focus();}
});
document.addEventListener('input',event=>{if(event.target.matches('.sf-section-name'))sfRefreshSections();});
q('#structureForm').addEventListener('submit',()=>{q('#schema_json').value=JSON.stringify(sfSerialize());});
sfRefreshSections();

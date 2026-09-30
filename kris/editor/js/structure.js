// Schema changes remain a normal POST: destructive changes go through the server impact view.
'use strict';
const SF_TYPES = [['text','Testo multilingua'],['richtext','Testo formattato'],['image','Media / file'],['plain','Testo condiviso'],['array','Elenco annidato']];
// Il flag posts esiste solo sugli elenchi di primo livello.
const SF_POSTS='<label class="sf-posts-label" hidden><input type="checkbox" class="sf-posts"><span><strong>Mostra in Posts</strong><small>Accesso rapido dalla barra laterale; i nuovi elementi vengono aggiunti in cima.</small></span></label>';
function sfTypeChange(select) {
    const nested=select.closest('.sf-row').querySelector('.sf-nested');
    nested.style.display=select.value==='array'?'flex':'none';
    nested.querySelectorAll('.sf-name').forEach(input=>input.required=select.value==='array');
    const posts=select.closest('.sf-row').querySelector(':scope > .sf-posts-label');if(posts)posts.hidden=select.value!=='array';
    markAsDirty();
}
function sfAddField(container) {
    const row=document.createElement('div');row.className='sf-row';
    row.innerHTML=`<div class="sf-header"><input type="text" class="sf-name" placeholder="Nome tecnico" aria-label="Nome tecnico del campo" required pattern="[a-z0-9_]+"><select class="sf-type" aria-label="Tipo del campo">${SF_TYPES.map(([v,l])=>`<option value="${v}">${l}</option>`).join('')}</select><button type="button" class="icon-button danger sf-remove" aria-label="Rimuovi campo">&times;</button></div><label class="sf-description-label">Descrizione visibile<input type="text" class="sf-description" placeholder="Aiuta chi modifica il contenuto"></label>${container.id==='root-schema'?SF_POSTS:''}<div class="sf-nested" style="display:none"><button type="button" class="btn btn-white sf-add-child">+ Sotto-campo</button></div>`;
    const add=[...container.children].find(child=>child.classList.contains('sf-add-child'));
    container.insertBefore(row,add||null);q('.sf-name',row).focus();markAsDirty();
}
function sfSerialize(container) {
    return [...container.children].filter(c=>c.classList.contains('sf-row')).map(row=>{
        const entry={name:q('.sf-name',row).value.trim(),type:q('.sf-type',row).value,description:q('.sf-description',row).value};
        if(entry.type==='array')entry.of=sfSerialize(q('.sf-nested',row));
        const posts=row.querySelector(':scope > .sf-posts-label .sf-posts');if(posts)entry.posts=entry.type==='array'&&posts.checked;
        return entry;
    });
}
document.addEventListener('click',async event=>{
    const button=event.target.closest('button');if(!button)return;
    if(button.hasAttribute('data-add-root'))sfAddField(q('#root-schema'));
    if(button.classList.contains('sf-add-child'))sfAddField(button.closest('.sf-nested'));
    if(button.classList.contains('sf-remove')){
        const row=button.closest('.sf-row');const name=q('.sf-name',row).value;
        const yes=await choose('Rimuovere questo campo dal modello?',`Il campo ${name||'senza nome'} sarà rimosso dalla struttura proposta. Prima del salvataggio verrà verificato l’impatto sui contenuti esistenti.`,[{label:'Mantieni campo',value:false},{label:'Rimuovi campo',value:true,kind:'btn-danger'}]);
        if(yes){row.remove();markAsDirty();q('[data-add-root]').focus();}
    }
});
document.addEventListener('change',event=>{if(event.target.matches('.sf-type'))sfTypeChange(event.target);});
q('#structureForm').addEventListener('submit',()=>{q('#schema_json').value=JSON.stringify(sfSerialize(q('#root-schema')));});

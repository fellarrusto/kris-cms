// Progressive enhancement of the real PHP forms: server responses remain authoritative.
'use strict';
const currentForm = document.querySelector('[data-dirty-form]');
let hasUnsavedChanges = false;
let saving = false;
let uploadBusy = false;
let baseline = '';
let mediaTarget = null;
let mediaCallback = null;
let selectedMedia = '';
let toastTimer;
const csrfMeta = document.querySelector('meta[name="kris-csrf"]');
const csrf = () => csrfMeta?.content || '';
const q = (s, root = document) => root.querySelector(s);
const qa = (s, root = document) => [...root.querySelectorAll(s)];

function snapshot() {
    if (!currentForm) return '';
    return JSON.stringify(qa('input:not([type="hidden"]), textarea[name], select', currentForm)
        .filter(el => !el.closest('.tox'))
        .map(el => [el.name || el.className, el.type === 'checkbox' ? el.checked : el.value]));
}
function markAsDirty() {
    hasUnsavedChanges = snapshot() !== baseline;
    updateSaveState();
}
function updateSaveState(label) {
    const bar = q('[data-savebar]');
    if (!bar) return;
    bar.classList.toggle('is-dirty', hasUnsavedChanges);
    bar.classList.toggle('is-saving', saving);
    q('[data-save-status]').textContent = label || (saving ? 'Salvataggio in corso...' : hasUnsavedChanges ? 'Hai modifiche da salvare' : 'Tutte le modifiche sono salvate');
    q('[data-discard]').hidden = !hasUnsavedChanges;
    qa('button', bar).forEach(b => b.disabled = saving);
    q('[data-save-button]').disabled = saving || !hasUnsavedChanges;
}
function toast(message) {
    const el = q('#toast'); clearTimeout(toastTimer);
    el.textContent = message; el.hidden = false;
    toastTimer = setTimeout(() => { el.hidden = true; }, 6000);
}
function feedback(message, session = false) {
    const box = q('#request-feedback');
    box.replaceChildren(); box.hidden = false;
    const text = document.createElement('p'); text.textContent = message; box.append(text);
    if (session) {
        const link = document.createElement('a'); link.href = 'index.php'; link.target = '_blank'; link.rel = 'noopener'; link.className = 'btn btn-white'; link.textContent = 'Apri accesso in una nuova scheda'; box.append(link);
        const hint = document.createElement('p'); hint.textContent = 'Accedi senza chiudere questa pagina, poi riprova il salvataggio. I campi sono ancora qui.'; box.append(hint);
    }
    const retry = document.createElement('button'); retry.type = 'button'; retry.className = 'btn btn-white'; retry.textContent = 'Riprova il salvataggio'; retry.onclick = () => saveCurrent(); box.append(retry);
    box.scrollIntoView({block:'center', behavior:'smooth'});
}
function choose(title, text, options) {
    return new Promise(resolve => {
        const dialog = q('#confirmDialog');
        q('#confirmTitle').textContent = title; q('#confirmText').textContent = text;
        const actions = q('#confirmActions'); actions.replaceChildren();
        let result = null;
        options.forEach(option => {
            const button = document.createElement('button'); button.type = 'button'; button.textContent = option.label;
            button.className = 'btn ' + (option.kind || 'btn-white');
            button.onclick = () => { result = option.value; dialog.close(); };
            actions.append(button);
        });
        dialog.addEventListener('close', () => resolve(result), {once:true});
        dialog.showModal(); actions.firstElementChild?.focus();
    });
}
async function allowLeave() {
    if (saving || uploadBusy) { toast('Attendi il completamento dell’operazione.'); return false; }
    if (!hasUnsavedChanges) return true;
    const canSave = currentForm?.hasAttribute('data-async-save');
    const result = await choose('Vuoi salvare prima di continuare?',
        'Hai modifiche non salvate. Puoi restare qui, scartarle oppure salvarle prima di proseguire.',
        [{label:'Resta qui',value:'stay'}, {label:'Continua senza salvare',value:'discard',kind:'btn-danger'},
         {label:canSave?'Salva e continua':'Salva prima',value:'save',kind:'btn-primary'}]);
    if (result === 'discard') return true;
    if (result === 'save') {
        if (canSave) return await saveCurrent();
        currentForm.requestSubmit(q('[data-save-button]'));
    }
    return false;
}
async function saveCurrent() {
    if (!currentForm || saving) return false;
    window.tinymce?.triggerSave();
    if (!currentForm.reportValidity()) return false;
    const body = new FormData(currentForm);
    saving = true; currentForm.inert = true; updateSaveState();
    q('#request-feedback').hidden = true;
    try {
        // Refresh the CSRF token without reloading (also allows retry after re-login).
        const tokenResponse = await fetch('index.php?editor_session=1', {credentials:'same-origin',cache:'no-store',headers:{'X-Kris-Editor':'session'}});
        const session = await tokenResponse.json().catch(() => null);
        if (!tokenResponse.ok || !session?.csrf) {
            feedback('La sessione è scaduta. Il tuo lavoro non è stato inviato.', true); return false;
        }
        csrfMeta.content = session.csrf;
        qa('input[name="csrf"]').forEach(el => el.value = session.csrf);
        body.set('csrf', session.csrf);
        const response = await fetch(currentForm.action || location.href, {method:'POST',body,credentials:'same-origin',headers:{'X-Kris-Editor':'save'}});
        const result = await response.json().catch(() => null);
        if (!result) { feedback('Non possiamo confermare il salvataggio. I campi sono ancora qui. Verifica l’accesso prima di riprovare.', true); return false; }
        if (!response.ok || !result.ok) { feedback(result.message || 'Salvataggio non riuscito. Le modifiche restano nel modulo.'); return false; }
        baseline = snapshot(); hasUnsavedChanges = false;
        toast(result.message || 'Modifiche salvate.');
        return true;
    } catch (error) {
        feedback('Non possiamo confermare il salvataggio: la connessione si è interrotta. Le modifiche restano qui; verifica la connessione prima di riprovare.');
        return false;
    } finally {
        saving = false; currentForm.inert = false; updateSaveState();
    }
}
function submitNative(form, submitter) {
    if (submitter?.name) {
        const input = document.createElement('input'); input.type = 'hidden'; input.name = submitter.name; input.value = submitter.value; form.append(input);
    }
    hasUnsavedChanges = false;
    const buttons = qa('button', form); buttons.forEach(b => b.disabled = true);
    HTMLFormElement.prototype.submit.call(form);
}
function safeMediaSource(url) {
    if (/^https?:\/\//i.test(url)) return url;
    if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(url)) return '';
    return url.startsWith('/') || url.startsWith('../') ? url : '../' + url;
}
function refreshPreview(input) {
    const holder = qa('[data-preview-for]').find(el => el.dataset.previewFor === input.id);
    if (!holder) return;
    holder.replaceChildren(); const source = safeMediaSource(input.value);
    if (source && !/\.pdf(?:[?#]|$)/i.test(source)) { const img = new Image(); img.src = source; img.alt = 'Anteprima del file selezionato'; holder.append(img); }
    else holder.textContent = source ? 'PDF' : 'Nessun file';
}
function pickMedia(id) {
    mediaTarget = document.getElementById(id); mediaCallback = null;
    showMediaPicker(mediaTarget?.value || '');
}
function openCmsMediaPicker(callback, value) { mediaTarget = null; mediaCallback = callback; showMediaPicker(value || ''); }
function showMediaPicker(value) {
    selectedMedia = value;
    const search = q('[data-filter="picker"]'); search.value = ''; filterList(search);
    qa('[data-media-url]').forEach(button => { const selected = button.dataset.mediaUrl === value; button.classList.toggle('selected',selected); button.setAttribute('aria-pressed',String(selected)); });
    q('#applyMedia').disabled = !selectedMedia;
    q('#mediaSelectionLabel').textContent = selectedMedia ? decodeURIComponent(selectedMedia.split('/').pop()) : 'Nessun file selezionato';
    q('#mediaOverlay').showModal(); search.focus();
}
function filterList(input) {
    const list = q(`[data-filter-list="${input.dataset.filter}"]`); if (!list) return;
    let count = 0;
    qa('[data-search]',list).forEach(item => { item.hidden = !item.dataset.search.toLocaleLowerCase().includes(input.value.toLocaleLowerCase()); if (!item.hidden) count++; });
    const empty = q(`[data-filter-empty="${input.dataset.filter}"]`); if (empty) empty.hidden = count !== 0;
    // Reordering a filtered list is ambiguous; keep it available only in the full list.
    qa('[data-move]',list).forEach(button => button.disabled = !!input.value || (button.dataset.move === '-1' ? !button.closest('tr').previousElementSibling : !button.closest('tr').nextElementSibling));
    qa('tr[draggable]',list).forEach(row=>row.draggable=!input.value);
}
function appendMedia(url, file) {
    const choice = document.createElement('button'); choice.type = 'button'; choice.className = 'media-choice'; choice.dataset.mediaUrl = url; choice.dataset.search = file.name; choice.setAttribute('aria-pressed','false');
    const art = document.createElement('span'); art.className = 'media-art';
    if (/\.pdf$/i.test(url)) { const label=document.createElement('span'); label.className='file-placeholder';label.textContent='PDF';art.append(label); }
    else { const img=new Image();img.src=safeMediaSource(url);img.alt='';art.append(img); }
    const caption=document.createElement('span');caption.className='media-caption';caption.textContent=file.name;choice.append(art,caption);q('#mediaGrid').prepend(choice);
    const grid=q('#libraryGrid');
    if(grid){
        const card=document.createElement('article');card.className='media-card';card.dataset.search=file.name;
        const link=document.createElement('a');link.className='media-art';link.href=safeMediaSource(url);link.target='_blank';link.rel='noopener';link.setAttribute('aria-label','Apri '+file.name);link.append(...[...art.childNodes].map(n=>n.cloneNode(true)));
        const text=document.createElement('div');text.className='media-caption';const title=document.createElement('strong');title.textContent=file.name;
        const size=document.createElement('small');size.textContent=Math.ceil(file.size/1024)+' KB';
        const actions=document.createElement('div');actions.className='media-actions';const copy=document.createElement('button');copy.type='button';copy.className='text-link';copy.dataset.copyUrl=url;copy.textContent='Copia URL';actions.append(copy);
        const del=document.createElement('form');del.method='POST';del.dataset.confirmTitle='Eliminare questo file?';del.dataset.confirm='Il file verrà eliminato definitivamente. Verifica che non sia già utilizzato nel sito.';del.dataset.confirmLabel='Elimina file';
        for(const [name,value] of Object.entries({csrf:csrf(),delete_media:'1',file_name:decodeURIComponent(url.split('/').pop())})){const hidden=document.createElement('input');hidden.type='hidden';hidden.name=name;hidden.value=value;del.append(hidden);}
        const remove=document.createElement('button');remove.className='text-link danger';remove.textContent='Elimina';del.append(remove);actions.append(del);text.append(title,size,actions);card.append(link,text);grid.prepend(card);
        q('[data-media-count]').textContent=grid.children.length+' file';
    }
    qa('[data-filter]').forEach(filterList);
}
async function uploadFile(file, input) {
    if (uploadBusy) { toast('Attendi il caricamento in corso.'); return; }
    uploadBusy=true;
    const scope=input.closest('dialog') || document;
    const results=q('[data-upload-results]',scope);const row=document.createElement('div');row.className='upload-result';
    const title=document.createElement('strong');title.textContent=file.name;
    const status=document.createElement('p');status.textContent='Caricamento in corso...';
    row.append(title,status);results.append(row);
    qa('[data-upload-trigger], [data-upload-form] button').forEach(b=>b.disabled=true);
    const body=new FormData();body.append('file',file);body.append('csrf',csrf());
    try {
        const response=await fetch('upload.php',{method:'POST',body,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        const result=await response.json().catch(()=>null);
        if(!response.ok||!result?.url)throw new Error(result?.error || (response.status===403?'Sessione scaduta. Accedi di nuovo e riprova.':'Non possiamo confermare il caricamento. Verifica la libreria prima di riprovare.'));
        status.textContent='Caricato. Il file è disponibile nella libreria.';row.classList.add('success');appendMedia(result.url,file);
        if(scope instanceof HTMLDialogElement){selectedMedia=result.url;const button=qa('[data-media-url]').find(b=>b.dataset.mediaUrl===result.url);button?.click();}
        toast('File caricato.');
    } catch(error) {
        row.classList.add('error');status.textContent=error.message;
        const retry=document.createElement('button');retry.type='button';retry.className='btn btn-white';retry.textContent='Riprova';retry.onclick=()=>{row.remove();uploadFile(file,input);};row.append(retry);
    } finally { uploadBusy=false;input.value='';qa('[data-upload-trigger], [data-upload-form] button').forEach(b=>b.disabled=false); }
}
async function reorder(table, row, before) {
    const form = document.getElementById(table.dataset.reorderForm);
    if (!form || saving || uploadBusy) return;
    const tbody = table.tBodies[0];
    const previousOrder = [...tbody.children];
    const focused = document.activeElement;
    const scrollPosition = {left:window.scrollX, top:window.scrollY};
    const scrollContainer = table.closest('.table-scroll');
    const scrollLeft = scrollContainer?.scrollLeft;
    let notice = table.parentElement.querySelector('[data-order-feedback]');
    if (!notice) {
        notice = document.createElement('p'); notice.dataset.orderFeedback = '';
        notice.className = 'hint'; notice.setAttribute('role', 'status');
        table.after(notice);
    }
    notice.textContent = 'Salvataggio ordine in corso...';
    saving = true; updateSaveState(); table.setAttribute('aria-busy', 'true');
    tbody.insertBefore(row, before);
    qa('[data-move]', table).forEach(button => button.disabled = true);
    q('[name="order"]', form).value = qa('tr[data-id]', tbody).map(r => r.dataset.id).join(',');
    const body = new FormData(form);
    try {
        const tokenResponse = await fetch('index.php?editor_session=1', {credentials:'same-origin', cache:'no-store', headers:{'X-Kris-Editor':'session'}});
        const session = await tokenResponse.json().catch(() => null);
        if (!tokenResponse.ok || !session?.csrf) throw new Error('Sessione scaduta. Accedi in una nuova scheda e riprova.');
        csrfMeta.content = session.csrf;
        qa('input[name="csrf"]').forEach(input => input.value = session.csrf);
        body.set('csrf', session.csrf);
        const response = await fetch(form.action, {method:'POST', body, credentials:'same-origin', headers:{'X-Kris-Editor':'reorder'}});
        const result = await response.json().catch(() => null);
        if (!response.ok || !result?.ok) throw new Error(result?.message || 'Impossibile confermare il nuovo ordine. Riprova o ricarica dopo aver salvato i testi.');
        notice.textContent = 'Ordine salvato.';
    } catch (error) {
        previousOrder.forEach(element => tbody.append(element));
        notice.textContent = error.message;
        notice.setAttribute('role', 'alert');
    } finally {
        saving = false; updateSaveState(); table.removeAttribute('aria-busy');
        qa('[data-move]', table).forEach(button => {
            const tr = button.closest('tr');
            button.disabled = button.dataset.move === '-1' ? !tr.previousElementSibling : !tr.nextElementSibling;
        });
        if (focused instanceof HTMLElement && !focused.disabled) focused.focus({preventScroll:true});
        if (scrollContainer) scrollContainer.scrollLeft = scrollLeft;
        window.scrollTo({...scrollPosition, behavior:'instant'});
    }
}
function initDnd() {
    qa('.dnd-table').forEach(table=>{
        let moving=null;
        table.addEventListener('dragstart',event=>{
            const row=event.target.closest('tr[data-id]');
            if(!row||event.target.closest('a,button,input')){event.preventDefault();return;}
            moving=row;row.classList.add('dnd-dragging');event.dataTransfer.effectAllowed='move';event.dataTransfer.setData('text/plain',row.dataset.id);
        });
        table.addEventListener('dragover',event=>{if(moving){event.preventDefault();event.dataTransfer.dropEffect='move';}});
        table.addEventListener('drop',event=>{const row=event.target.closest('tr[data-id]');if(!row||!moving||row===moving)return;event.preventDefault();const before=event.clientY<row.getBoundingClientRect().top+row.offsetHeight/2?row:row.nextElementSibling;reorder(table,moving,before);});
        table.addEventListener('dragend',()=>{moving?.classList.remove('dnd-dragging');moving=null;});
    });
}

document.addEventListener('click',async event=>{
    const target=event.target.closest('button,a');if(!target)return;
    if(target.matches('[data-close-dialog]')) { event.preventDefault();if(uploadBusy){toast('Attendi il caricamento prima di chiudere.');return;}target.closest('dialog').close();return; }
    if(target.dataset.openDialog){event.preventDefault();document.getElementById(target.dataset.openDialog).showModal();return;}
    if(target.hasAttribute('data-discard')){
        event.preventDefault();const result=await choose('Annullare le modifiche?','Tornerai all’ultima versione salvata. Le modifiche del modulo verranno scartate.',[{label:'Continua a modificare',value:false},{label:'Annulla modifiche',value:true,kind:'btn-danger'}]);
        if(result){hasUnsavedChanges=false;location.assign(location.pathname+location.search);}return;
    }
    if(target.dataset.editorLanguage){
        event.preventDefault();const lang=target.dataset.editorLanguage;
        qa('[data-language-panel]').forEach(panel=>panel.hidden=panel.dataset.languagePanel!==lang);
        qa('[data-editor-language]').forEach(button=>{const active=button.dataset.editorLanguage===lang;button.classList.toggle('active',active);button.setAttribute('aria-pressed',String(active));});
        try { sessionStorage.setItem('kris-editor-language',lang); } catch {} return;
    }
    if(target.dataset.pickMedia){event.preventDefault();pickMedia(target.dataset.pickMedia);return;}
    if(target.dataset.mediaUrl){
        event.preventDefault();selectedMedia=target.dataset.mediaUrl;
        qa('[data-media-url]').forEach(b=>{const selected=b===target;b.classList.toggle('selected',selected);b.setAttribute('aria-pressed',String(selected));});
        q('#mediaSelectionLabel').textContent=decodeURIComponent(selectedMedia.split('/').pop());q('#applyMedia').disabled=false;return;
    }
    if(target.id==='applyMedia'){
        event.preventDefault();if(mediaCallback)mediaCallback(selectedMedia,{title:decodeURIComponent(selectedMedia.split('/').pop())});
        else if(mediaTarget){mediaTarget.value=selectedMedia;refreshPreview(mediaTarget);}
        window.tinymce?.triggerSave();markAsDirty();q('#mediaOverlay').close();return;
    }
    if(target.dataset.uploadTrigger){event.preventDefault();document.getElementById(target.dataset.uploadTrigger).click();return;}
    if(target.dataset.copyUrl){event.preventDefault();try{await navigator.clipboard.writeText(target.dataset.copyUrl);toast('URL copiato.');}catch{const input=document.createElement('input');input.value=target.dataset.copyUrl;input.readOnly=true;target.closest('.media-caption').append(input);input.select();toast('Copia l’URL selezionato con Ctrl/Cmd+C.');}return;}
    if(target.dataset.move){event.preventDefault();const row=target.closest('tr');const before=target.dataset.move==='-1'?row.previousElementSibling:row.nextElementSibling?.nextElementSibling;await reorder(target.closest('table'),row,before);return;}
    if(target.tagName==='A'&&target.target!=='_blank'&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.altKey&&event.button===0){
        const url=new URL(target.href,location.href);
        if(url.pathname===location.pathname&&url.search===location.search&&url.hash)return;
        if(hasUnsavedChanges||saving||uploadBusy){event.preventDefault();if(await allowLeave()){hasUnsavedChanges=false;location.assign(target.href);}}
    }
});
document.addEventListener('submit',async event=>{
    if(event.defaultPrevented)return;
    const form=event.target;const submitter=event.submitter;
    if(form.matches('[data-upload-form]')){event.preventDefault();const input=q('[data-upload-input]',form);if(input.files[0])uploadFile(input.files[0],input);return;}
    if(form.matches('[data-async-save]')){event.preventDefault();saveCurrent();return;}
    if(form===currentForm){hasUnsavedChanges=false;return;}
    event.preventDefault();
    if(!await allowLeave())return;
    if(form.dataset.confirm){const yes=await choose(form.dataset.confirmTitle||'Conferma operazione',form.dataset.confirm,[{label:'Annulla',value:false},{label:form.dataset.confirmLabel||'Conferma',value:true,kind:'btn-danger'}]);if(!yes)return;}
    submitNative(form,submitter);
});
document.addEventListener('input',event=>{
    if(event.target.matches('[data-filter]'))filterList(event.target);
    if(currentForm?.contains(event.target))markAsDirty();
    if(event.target.matches('[data-media-input]'))refreshPreview(event.target);
});
document.addEventListener('change',event=>{
    if(currentForm?.contains(event.target))markAsDirty();
    if(event.target.matches('[data-upload-input]')&&event.target.files[0])uploadFile(event.target.files[0],event.target);
});
qa('dialog').forEach(dialog=>dialog.addEventListener('cancel',event=>{if(uploadBusy){event.preventDefault();toast('Attendi il caricamento prima di chiudere.');}}));
window.addEventListener('beforeunload',event=>{if(hasUnsavedChanges||saving||uploadBusy){event.preventDefault();event.returnValue='';}});
document.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'&&currentForm&&!q('dialog[open]')){event.preventDefault();currentForm.requestSubmit(q('[data-save-button]'));}});
baseline=snapshot();initDnd();
if(currentForm)updateSaveState();
try { const lang=sessionStorage.getItem('kris-editor-language');qa('[data-editor-language]').find(b=>b.dataset.editorLanguage===lang)?.click(); } catch {}
if(q('.richtext')){
    if(window.tinymce){
        tinymce.init({selector:'.richtext',height:300,menubar:false,plugins:'image link lists code fullscreen',toolbar:'undo redo | blocks | bold italic | bullist numlist | link image | code fullscreen',file_picker_callback:openCmsMediaPicker,
            setup(editor){editor.on('change input undo redo',()=>{editor.save();markAsDirty();});}
        });
    }else{toast('Editor formattato non disponibile. Puoi comunque modificare e salvare il testo HTML.');}
}

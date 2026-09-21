'use strict';
const $ = (selector, scope = document) => scope.querySelector(selector);
const clone = value => JSON.parse(JSON.stringify(value));
const escapeHtml = value => String(value ?? '').replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
const icons = {
  pages:'<rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
  media:'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1.5"/><path d="m3 17 5-5 4 4 4-6 5 6"/>',
  structure:'<rect x="8" y="3" width="8" height="5" rx="1"/><rect x="3" y="16" width="6" height="5" rx="1"/><rect x="15" y="16" width="6" height="5" rx="1"/><path d="M12 8v4M6 16v-4h12v4"/>',
  settings:'<path d="M4 7h16M4 17h16"/><circle cx="9" cy="7" r="3" fill="currentColor"/><circle cx="16" cy="17" r="3" fill="currentColor"/>',
  check:'<path d="m5 12 4 4L19 6"/>', plus:'<path d="M12 5v14M5 12h14"/>',
  up:'<path d="m6 14 6-6 6 6"/>',down:'<path d="m6 10 6 6 6-6"/>',
  close:'<path d="m6 6 12 12M6 18 18 6"/>',search:'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
  trash:'<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>',
  upload:'<path d="M12 16V3m-5 5 5-5 5 5M4 15v6h16v-6"/>',
  arrow:'<path d="M5 12h14m-6-6 6 6-6 6"/>',warning:'<path d="m12 3 10 18H2L12 3Z"/><path d="M12 9v5M12 17v1"/>',
  lock:'<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
};
function icon(name) { return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[name] || icons.pages}</svg>`; }
const initial = {
  langs:['it','en'],
  schema:[{name:'title',label:'Titolo principale',type:'Testo',required:true},{name:'intro',label:'Descrizione',type:'Testo lungo'},{name:'image',label:'Immagine di copertina',type:'Immagine'},{name:'features',label:'Servizi in evidenza',type:'Elenco'}],
  pages:[
    {id:'home',label:'Homepage',title:{it:'Le idee prendono forma.',en:'Ideas take shape.'},intro:{it:'Progettiamo esperienze digitali semplici, curate e fatte per durare. Diamo spazio a quello che rende unico il tuo progetto.',en:'Thoughtful digital experiences, designed to last.'},image:'m1',features:[
      {id:'f1',title:'Strategia e identità',description:'Troviamo una direzione chiara per il tuo progetto.',color:''},
      {id:'f2',title:'Design e sviluppo',description:'Dalle prime idee a un’esperienza che funziona.',color:'sand'},
      {id:'f3',title:'Cura nel tempo',description:'Facciamo crescere il tuo progetto insieme.',color:'blue'}]},
    {id:'studio',label:'Il nostro studio',title:{it:'Un piccolo studio. Grandi possibilità.',en:''},intro:{it:'Crediamo nelle cose fatte bene, insieme.',en:''},image:'m2',features:[]}
  ]
};
const state = {saved:clone(initial),draft:clone(initial),view:'edit',selectedId:'home',lang:'it',saving:false,error:null,invalid:false,lastSaved:null,filter:'',mediaFilter:'',selectedMedia:null,picker:false,dialog:null,childBaseline:'',pending:null,uploading:false,toastTimers:[]};
let media = [{id:'m1',name:'paesaggio-verde.webp',size:'184 KB',color:''},{id:'m2',name:'forme-naturali.webp',size:'210 KB',color:'sand'},{id:'m3',name:'orizzonte-blu.webp',size:'156 KB',color:'blue'},{id:'m4',name:'studio-dettaglio.webp',size:'132 KB',color:'sand'},{id:'m5',name:'materiali.webp',size:'198 KB',color:''},{id:'m6',name:'architettura.webp',size:'224 KB',color:'blue'}];
const dirty = () => JSON.stringify(state.saved) !== JSON.stringify(state.draft);
const page = () => state.draft.pages.find(item => item.id === state.selectedId) || state.draft.pages[0];
function artwork(item, classes='') { return `<div class="art ${item?.color || ''} ${classes}">${item?.url ? `<img src="${escapeHtml(item.url)}" alt="">` : ''}</div>`; }
function imageForPage() { return media.find(item => item.id === page()?.image) || media[0]; }
function previewMarkup(large=false, saved=false) {
  const current = saved ? state.saved.pages.find(item=>item.id===state.selectedId) || state.saved.pages[0] : page();
  if (!current) return '<p>Nessun contenuto da mostrare.</p>';
  return `<div class="preview-card ${large?'preview-large':''}"><div class="preview-browser"><i></i><i></i><i></i><small>studioforma.example</small></div>${artwork(media.find(item=>item.id===current.image),'preview-art')}<div class="preview-copy"><h3 id="${large?'modal':'side'}-preview-title">${escapeHtml(current.title[state.lang] || current.title.it || 'Il tuo titolo, qui.')}</h3><p id="${large?'modal':'side'}-preview-intro">${escapeHtml(current.intro[state.lang] || current.intro.it || 'Una breve descrizione del tuo progetto.')}</p><span class="preview-link">Scopri il nostro approccio ↗</span></div></div>`;
}
function languageButtons() { return `<div class="language-switch" role="group" aria-label="Lingua di modifica">${state.draft.langs.map(lang=>`<button data-action="language" data-lang="${lang}" aria-pressed="${state.lang===lang}" class="${state.lang===lang?'active':''}">${lang==='it'?'Italiano':'English'} <span class="dot" aria-hidden="true"></span></button>`).join('')}</div>`; }
function heading(title,description,action='') { return `<div class="page-heading"><div><div class="heading-title"><h1>${title}</h1>${state.view==='edit'?'<span class="tag">PAGINA</span>':''}</div><p>${description}</p></div>${action?`<div class="page-actions">${action}</div>`:''}</div>`; }
function render() {
  const active = state.view === 'edit' ? 'contents' : state.view;
  $('#navigation').innerHTML = [['contents','pages','Contenuti'],['media','media','Libreria media'],['structure','structure','Struttura'],['settings','settings','Impostazioni']].map(([view,svg,label],index)=>`${index===2?'<div class="nav-separator"></div><p class="nav-label">CONFIGURAZIONE</p>':''}<button class="nav-item ${active===view?'active':''}" data-action="navigate" data-view="${view}" ${active===view?'aria-current="page"':''}>${icon(svg)}${label}${view==='contents'?`<span class="nav-count">${state.draft.pages.length}</span>`:''}</button>`).join('');
  $('#breadcrumbs').innerHTML = `<span>Studio Forma</span><span aria-hidden="true">/</span>${state.view==='edit'?`<button data-action="navigate" data-view="contents">Contenuti</button><span aria-hidden="true">/</span><strong>${escapeHtml(page()?.label)}</strong>`:`<strong>${{contents:'Contenuti',media:'Libreria media',structure:'Struttura',settings:'Impostazioni'}[state.view]}</strong>`}`;
  const views = {edit:renderEdit,contents:renderContents,media:renderMedia,structure:renderStructure,settings:renderSettings};
  $('#main').innerHTML = `<div class="page">${errorMarkup()}${views[state.view]()}</div>`;
  renderSavebar();
}
function errorMarkup(){
  if(!state.error)return '';
  const conflict=state.error==='conflict';
  return `<section class="banner error" role="alert">${icon('warning')}<div class="banner-body"><strong>${conflict?'Questo contenuto ha una versione più recente.':state.error==='validation'?'Controlla il titolo della pagina.':'Non siamo riusciti a salvare.'}</strong><p>${conflict?'Le tue modifiche sono ancora qui. Confronta le versioni prima di continuare.':state.error==='validation'?'Il titolo in italiano è obbligatorio. Gli altri dati sono stati mantenuti.':'Le tue modifiche sono ancora nella pagina. Controlla la connessione e riprova.'}</p>${state.error==='validation'?'':`<button class="button" data-action="${conflict?'conflict':'save'}">${conflict?'Confronta le versioni':'Riprova il salvataggio'}</button>`}</div></section>`;
}
function renderEdit(){
  const p=page(); if(!p){state.view='contents';return renderContents();}
  const img=imageForPage();
  return `${heading(escapeHtml(p.label),'Dai voce al tuo sito, un dettaglio alla volta.',`<button class="button quiet" data-action="navigate" data-view="structure">${icon('structure')}Struttura</button>`)}
    <div class="section-tabs"><button class="active" aria-current="page">Contenuto della pagina</button><button data-action="jump-features">Servizi in evidenza <span class="tag">${p.features.length}</span></button></div>
    <div class="edit-grid"><div>
      <section class="card"><header class="card-head"><span class="section-number">01</span><div><h2>La prima impressione</h2><p>Titolo, descrizione e immagine della tua pagina.</p></div></header><div class="card-body">${languageButtons()}
      <div class="field"><div class="label-line"><label for="page-title">Titolo principale ${state.lang==='it'?'<span aria-label="obbligatorio">*</span>':''}</label><small>${state.lang.toUpperCase()}</small></div><input type="text" id="page-title" data-field="title" value="${escapeHtml(p.title[state.lang] || '')}" aria-describedby="title-hint${state.invalid?' title-error':''}" ${state.invalid?'aria-invalid="true"':''}><p class="hint" id="title-hint">Una frase chiara che racconti chi sei.</p>${state.invalid?'<p id="title-error" class="field-error">Inserisci un titolo in italiano.</p>':''}</div>
      <div class="field"><div class="label-line"><label for="page-intro">Descrizione</label><small>${state.lang.toUpperCase()}</small></div><textarea id="page-intro" data-field="intro">${escapeHtml(p.intro[state.lang] || '')}</textarea><p class="hint">Puoi cambiare lingua senza perdere le modifiche.</p></div>
      <div class="field"><div class="label-line"><label>Immagine di copertina</label><small>CONDIVISA TRA LE LINGUE · DEMO</small></div><div class="cover-row">${artwork(img,'cover-thumb')}<div class="cover-info"><strong>${escapeHtml(img?.name)}</strong><small>Immagine selezionata · ${escapeHtml(img?.size)}</small><button class="button" data-action="media-picker">${icon('media')}Cambia immagine</button></div></div></div>
      </div></section>
      <section class="card" id="features-section"><header class="card-head"><span class="section-number">02</span><div><h2>Servizi in evidenza</h2><p>Ogni elemento diventa una scheda sul sito.</p></div><span class="tag right">${p.features.length} elementi</span></header><div class="feature-list">${p.features.length?p.features.map((f,index)=>`<div class="feature-row">${artwork(f,'feature-thumb')}<div class="feature-info"><strong>${escapeHtml(f.title)}</strong><small>Elemento ${index+1} · ${f.description?'Descrizione presente':'Descrizione da aggiungere'}</small></div><div class="feature-actions"><button class="icon-button" data-action="move-feature" data-id="${f.id}" data-direction="-1" aria-label="Sposta ${escapeHtml(f.title)} in alto" ${index===0?'disabled':''}>${icon('up')}</button><button class="icon-button" data-action="move-feature" data-id="${f.id}" data-direction="1" aria-label="Sposta ${escapeHtml(f.title)} in basso" ${index===p.features.length-1?'disabled':''}>${icon('down')}</button><button class="button quiet" data-action="edit-feature" data-id="${f.id}">Modifica</button></div></div>`).join(''):'<div class="empty"><h2>Il primo servizio, quando vuoi.</h2><p>Aggiungi un titolo e una descrizione. Potrai riordinarli in seguito.</p></div>'}</div><div class="card-foot"><button class="text-button" data-action="new-feature">+ Aggiungi un servizio</button><span>L’ordine viene salvato insieme alla pagina.</span></div></section>
    </div><aside class="context-column" aria-label="Contesto della pagina"><p class="side-label">UNO SGUARDO AL RISULTATO</p>${previewMarkup()}<p class="preview-note">Anteprima indicativa del contenuto.<br>Non è il rendering del template del sito.</p><p class="side-label">TRADUZIONI</p>${state.draft.langs.map(lang=>`<div class="translation-row"><span>${lang==='it'?'Italiano':'English'}</span><span id="translation-${lang}">${[p.title[lang],p.intro[lang]].filter(Boolean).length}/2 testi</span></div><div class="progress-track"><span id="progress-${lang}" style="width:${[p.title[lang],p.intro[lang]].filter(Boolean).length*50}%"></span></div>`).join('')}<div class="context-note"><h3>Salva quando sei pronto.</h3><p>Nel CMS attuale le modifiche salvate sono subito visibili sul sito. Non esiste una fase di pubblicazione separata.</p></div></aside></div>`;
}
function renderContents(){return `${heading('I tuoi contenuti','Tutto quello che racconta il tuo sito, in un unico posto.',`<button class="button primary" data-action="new-page">${icon('plus')}Nuovo contenuto</button>`)}<div class="toolbar"><div class="search-wrap">${icon('search')}<input id="content-search" type="search" placeholder="Cerca un contenuto…" aria-label="Cerca un contenuto" value="${escapeHtml(state.filter)}"></div><span class="tag">${state.draft.pages.length} contenuti</span></div><h2 class="collection-heading">${icon('pages')}Pagine del sito</h2><div id="content-results">${contentResults()}</div><div class="context-note"><h3>Scrivere e configurare sono due attività diverse.</h3><p>Per aggiungere campi o cambiare il modello, usa l’area Struttura. Qui puoi concentrarti sui contenuti.</p></div>`;}
function contentResults(){const pages=state.draft.pages.filter(p=>`${p.label} ${p.title.it}`.toLowerCase().includes(state.filter.toLowerCase()));return pages.length?`<div class="card"><table class="list-table"><thead><tr><th scope="col">Contenuto</th><th scope="col">Traduzioni</th><th scope="col"><span class="sr-only">Azioni</span></th></tr></thead><tbody>${pages.map(p=>`<tr><td><div class="list-title">${artwork(media.find(m=>m.id===p.image))}<div><strong>${escapeHtml(p.label)}</strong><small>${escapeHtml(p.title.it || 'Titolo da completare')}</small></div></div></td><td><span class="tag ${p.title.en?'green':'amber'}">${p.title.en?'IT + EN':'EN da completare'}</span></td><td><button class="button quiet" data-action="open-page" data-id="${p.id}">Modifica ${icon('arrow')}</button></td></tr>`).join('')}</tbody></table></div>`:`<div class="empty">${icon('search')}<h2>Nessun risultato per questa ricerca.</h2><p>Prova un altro nome oppure rimuovi il filtro.</p><button class="button" data-action="clear-search">Mostra tutti i contenuti</button></div>`;}
function mediaGrid(picker=false){const matches=media.filter(m=>m.name.toLowerCase().includes(state.mediaFilter.toLowerCase()));return matches.length?`<div class="media-grid">${matches.map(m=>`<button class="media-card ${state.selectedMedia===m.id?'selected':''}" data-action="${picker?'select-media':'media-detail'}" data-id="${m.id}" ${picker?`aria-pressed="${state.selectedMedia===m.id}"`:''}>${artwork(m)}${state.selectedMedia===m.id?'<span class="media-selection">✓</span>':''}<div class="media-caption"><strong>${escapeHtml(m.name)}</strong><small>${escapeHtml(m.size)} · ${m.url?'File locale':'Immagine dimostrativa'}</small></div></button>`).join('')}</div>`:'<div class="empty"><h2>Nessun file trovato.</h2><p>Prova un nome diverso.</p></div>';}
function renderMedia(){return `${heading('Libreria media','Le immagini del tuo sito, facili da trovare e riutilizzare.',`<button class="button primary" data-action="upload">${icon('upload')}Carica immagini</button>`)}<div class="toolbar"><div class="search-wrap">${icon('search')}<input type="search" id="media-search" aria-label="Cerca un file" placeholder="Cerca per nome file…" value="${escapeHtml(state.mediaFilter)}"></div><span class="tag">${media.length} immagini</span></div><div id="upload-progress" aria-live="polite"></div><div id="media-results">${mediaGrid()}</div><p class="hint" style="margin-top:18px">Prototipo: i file scelti vengono letti soltanto in questo browser. Nessun upload verso il CMS.</p>`;}
function renderStructure(){return `${heading('Struttura dei contenuti','Configura i campi che compongono le pagine del sito.')}<div class="banner">${icon('structure')}<div><strong>Un’area per chi costruisce il sito.</strong><p>Le modifiche al modello possono coinvolgere contenuti già esistenti. Prima di applicarle, controlla l’impatto.</p></div></div><div class="settings-layout"><section class="card"><header class="card-head"><div><h2>Modello · Pagine</h2><p>${state.draft.schema.length} campi · ${state.draft.pages.length} contenuti nel prototipo</p></div><button class="button right" data-action="new-field">${icon('plus')}Aggiungi campo</button></header>${state.draft.schema.map(f=>`<div class="schema-row"><span class="type-icon">${f.type==='Immagine'?'↗':f.type==='Elenco'?'≡':'Tt'}</span><div class="schema-info"><strong>${escapeHtml(f.label)}</strong><code>${escapeHtml(f.name)}</code></div><span class="tag">${escapeHtml(f.type)}</span><button class="button quiet" data-action="edit-field" data-id="${f.name}">Configura</button></div>`).join('')}</section><div class="context-note"><h3>Nome tecnico e nome visibile hanno scopi diversi.</h3><p>Il nome tecnico collega dati e template. Puoi migliorare l’etichetta senza cambiare quel collegamento.</p></div></div>`;}
function renderSettings(){return `${heading('Impostazioni','Le preferenze che aiutano a lavorare meglio.')}<div class="settings-layout"><section class="card"><header class="card-head">${icon('settings')}<div><h2>Lingue di modifica</h2><p>Scegli le lingue disponibili nell’editor.</p></div></header><div class="card-body">${['it','en'].map(lang=>`<label class="setting-row"><input type="checkbox" data-setting-lang="${lang}" ${state.draft.langs.includes(lang)?'checked':''} ${lang==='it'?'disabled':''}><span><strong>${lang==='it'?'Italiano':'English'}</strong><small>${lang==='it'?'Lingua principale del prototipo':'Traduzioni del sito'}</small></span>${lang==='it'?'<span class="tag">Principale</span>':''}</label>`).join('')}<div class="banner" style="margin:18px 0 0"><div><strong>Disattivare una lingua non cancella i suoi testi.</strong><p>Le traduzioni restano conservate e tornano disponibili quando riattivi la lingua. La visibilità sul sito si configura separatamente.</p></div></div></div></section><section class="card"><header class="card-head">${icon('lock')}<div><h2>Accesso all’editor</h2><p>Una sessione scaduta non deve farti perdere il lavoro.</p></div></header><div class="card-body"><p class="hint">Per provare il recupero, scegli “Sessione scaduta” nei controlli del prototipo e salva una modifica.</p></div></section></div>`;}
function renderSavebar(){
  const pending=dirty(); const disabled=state.saving||!pending;
  $('#savebar').innerHTML=`<div class="save-status"><span class="status-icon">${state.saving?'<span class="spinner"></span>':pending?'<span class="warning-dot"></span>':icon('check')}</span><div><strong>${state.saving?'Salvataggio in corso…':pending?'Hai modifiche da salvare':state.lastSaved?'Modifiche salvate':'Tutto pronto per lavorare'}</strong><small>${state.saving?'Attendi la conferma prima di uscire.':pending?'I cambiamenti restano qui finché non salvi.':state.lastSaved?`Ultimo salvataggio simulato alle ${state.lastSaved}`:'Le modifiche in questo prototipo restano in memoria.'}</small></div></div><div class="save-actions"><button class="button quiet" data-action="discard" ${disabled?'disabled':''}>Annulla modifiche</button><button class="button primary" data-action="save" ${disabled?'disabled':''}>${state.saving?'<span class="spinner"></span>':icon('check')}${state.saving?'Salvataggio…':state.view==='settings'?'Salva impostazioni':state.view==='structure'?'Applica modifiche':'Salva modifiche'}</button></div>`;
}
function updateFromInput(){
  renderSavebar();
  if(state.view==='edit'){
    const p=page(); if($('#side-preview-title'))$('#side-preview-title').textContent=p.title[state.lang]||p.title.it||'Il tuo titolo, qui.';
    if($('#side-preview-intro'))$('#side-preview-intro').textContent=p.intro[state.lang]||p.intro.it||'Una breve descrizione del tuo progetto.';
    const count=[p.title[state.lang],p.intro[state.lang]].filter(Boolean).length;
    if($('#translation-'+state.lang))$('#translation-'+state.lang).textContent=count+'/2 testi';
    if($('#progress-'+state.lang))$('#progress-'+state.lang).style.width=count*50+'%';
  }
}
function toast(message,undo){
  const el=document.createElement('div');el.className='toast';el.textContent=message;
  if(undo){const b=document.createElement('button');b.textContent='Annulla';b.onclick=()=>{undo();el.remove();};el.append(b);}
  $('#toasts').append(el);if(!undo)state.toastTimers.push(setTimeout(()=>el.remove(),6500));
  else {const b=document.createElement('button');b.textContent='×';b.setAttribute('aria-label','Chiudi notifica');b.onclick=()=>el.remove();el.append(b);}
}
function openDialog(title,description,body,footer,kind='',type='generic'){
  const dialog=$('#dialog');state.dialog=type;dialog.className=kind;
  dialog.innerHTML=`<header class="dialog-head"><div><h2 id="dialog-title">${title}</h2>${description?`<p>${description}</p>`:''}</div><button class="icon-button" data-action="close-dialog" aria-label="Chiudi finestra">${icon('close')}</button></header><div class="dialog-body">${body}</div>${footer?`<footer class="dialog-foot">${footer}</footer>`:''}`;
  if(!dialog.open)dialog.showModal();
  setTimeout(()=>{const target=$('[autofocus]',dialog)||$('input:not([disabled]),button',dialog);target?.focus();},0);
}
function closeDialog(force=false){
  if(state.uploading){toast('Attendi il completamento della simulazione di caricamento.');return;}
  if(!force && state.dialog==='feature' && childSignature()!==state.childBaseline){
    const footer=$('.dialog-foot');
    if(!$('#child-discard-warning'))footer.insertAdjacentHTML('beforebegin','<div id="child-discard-warning" class="banner" role="alert" style="margin:0 20px 15px"><div><strong>Le modifiche a questo servizio non sono state applicate.</strong><p>Continua a modificarlo oppure scarta solo questi cambiamenti.</p><button class="button danger" data-action="discard-child">Scarta e chiudi</button></div></div>');
    return;
  }
  $('#dialog').close();state.dialog=null;state.picker=false;
}
function navigate(view,id){
  if(state.saving){toast('Attendi la conferma del salvataggio.');return;}
  const go=()=>{state.view=view;if(id)state.selectedId=id;state.error=null;state.invalid=false;state.filter='';render();$('#main').focus();window.scrollTo(0,0);};
  if(dirty())guard(go);else go();
}
function guard(callback){state.pending=callback;openDialog('Vuoi salvare prima di uscire?','Le modifiche non sono ancora state salvate.','<p>Puoi salvarle e continuare, oppure uscire scartandole. Restando qui non perdi nulla.</p>','<button class="button" data-action="close-dialog" autofocus>Resta qui</button><button class="button danger" data-action="leave-discard">Esci senza salvare</button><button class="button primary" data-action="leave-save">Salva e continua</button>','','leave');}
async function save(callback){
  if(state.saving||!dirty())return;
  const invalid=state.draft.pages.find(p=>!p.title.it.trim());
  if(invalid){state.selectedId=invalid.id;state.view='edit';state.lang='it';state.error='validation';state.invalid=true;render();$('#page-title')?.focus();return;}
  state.saving=true;state.error=null;renderSavebar();
  $('#main').inert=true;
  const outcome=$('#scenario').value;$('#scenario').value='ok';
  await new Promise(resolve=>setTimeout(resolve,850));
  state.saving=false;$('#main').inert=false;
  if(outcome==='error'){state.error='error';render();$('.banner')?.scrollIntoView({block:'center'});return;}
  if(outcome==='conflict'){state.error='conflict';render();showConflict();return;}
  if(outcome==='session'){renderSavebar();openDialog('La sessione è scaduta.','Il contenuto del form è ancora qui.','<p>Accedi di nuovo e riprendi dal salvataggio. Nel prodotto, la riconnessione deve verificare la sessione senza ricaricare il modulo aperto.</p>','<button class="button" data-action="close-dialog">Resta nel modulo</button><button class="button primary" data-action="reconnect">Simula riconnessione</button>','','session');return;}
  state.saved=clone(state.draft);state.lastSaved=new Date().toLocaleTimeString('it-IT',{hour:'2-digit',minute:'2-digit'});state.invalid=false;
  $('#toasts').replaceChildren();render();toast('Modifiche salvate. Nel CMS saranno subito visibili sul sito.');
  if(callback)callback();
}
function discard(){openDialog('Annullare le modifiche?','Tornerai all’ultima versione salvata.','<p>Verranno scartati testi, selezioni e ordine modificati in questa schermata.</p>','<button class="button" data-action="close-dialog" autofocus>Continua a modificare</button><button class="button danger solid" data-action="confirm-discard">Annulla modifiche</button>');}
function openFeature(id){
  const f=page().features.find(item=>item.id===id)||{id:'',title:'',description:''};state.featureId=f.id;
  openDialog(f.id?'Modifica servizio':'Aggiungi un servizio',`Contenuti / ${escapeHtml(page().label)} / Servizi in evidenza`,`<p>Le modifiche al servizio verranno preparate nella pagina. Per renderle effettive, salva poi la pagina.</p><div class="field"><div class="label-line"><label for="child-title">Titolo del servizio *</label></div><input type="text" id="child-title" value="${escapeHtml(f.title)}" autofocus><div id="child-error" class="field-error" role="alert"></div></div><div class="field"><div class="label-line"><label for="child-description">Descrizione</label></div><textarea id="child-description">${escapeHtml(f.description)}</textarea></div>${f.id?'<div class="danger-zone"><div><h3>Rimuovi dalla pagina</h3><p>Potrai annullare finché non salvi.</p></div><button class="button danger" data-action="remove-feature">Rimuovi</button></div>':''}`,'<button class="button" data-action="close-dialog">Annulla</button><button class="button primary" data-action="apply-feature">Applica alla pagina</button>','sheet','feature');
  state.childBaseline=childSignature();
}
function childSignature(){return JSON.stringify([$('#child-title')?.value,$('#child-description')?.value]);}
function openPicker(){state.picker=true;state.selectedMedia=page().image;state.mediaFilter='';renderPicker();}
function renderPicker(){openDialog('Scegli un’immagine','Homepage / Immagine di copertina',`<div class="toolbar"><div class="search-wrap">${icon('search')}<input type="search" id="picker-search" placeholder="Cerca un’immagine…" aria-label="Cerca un’immagine" value="${escapeHtml(state.mediaFilter)}"></div><button class="button" data-action="upload">${icon('upload')}Carica</button></div><div id="upload-progress" aria-live="polite"></div><div id="picker-results">${mediaGrid(true)}</div><p class="hint" style="margin-top:15px">Scegli prima il file, poi conferma. Chiudere questa finestra non cambia la copertina.</p>`,'<button class="button" data-action="close-dialog">Annulla</button><button class="button primary" data-action="apply-media">Usa questa immagine</button>','wide','picker');}
function showConflict(){openDialog('Confronta prima di salvare','Scenario simulato · un’altra sessione ha aggiornato il contenuto.',`<p>Il tuo lavoro è conservato. Non sovrascriviamo automaticamente la versione sul sito.</p><div class="compare"><div><small>LA TUA VERSIONE</small><p>${escapeHtml(page()?.title.it || 'Modifiche locali')}</p></div><div><small>VERSIONE SERVER SIMULATA</small><p>Un nuovo modo di dare forma alle idee.</p></div></div><p>Nel prodotto, il confronto deve includere tutti i campi cambiati. Questo prototipo mostra solo il titolo.</p>`,'<button class="button" data-action="close-dialog" autofocus>Continua a modificare</button><button class="button" data-action="copy-draft">Copia il mio lavoro</button><button class="button primary" data-action="accept-remote">Usa la versione server</button>','wide','conflict');}
function openField(name){
  const field=state.draft.schema.find(f=>f.name===name)||{name:'',label:'',type:'Testo'};state.fieldId=field.name;
  openDialog(field.name?'Configura campo':'Nuovo campo','Modello Pagine · nessuna modifica ai template in questo prototipo.',`<div class="field"><div class="label-line"><label for="field-label">Nome visibile *</label></div><input type="text" id="field-label" value="${escapeHtml(field.label)}" autofocus></div><div class="field"><div class="label-line"><label for="field-name">Nome tecnico *</label></div><input type="text" id="field-name" value="${escapeHtml(field.name)}" ${field.name?'readonly':''}><p class="hint">Minuscole, numeri e underscore. Un nome esistente richiede una migrazione per essere cambiato.</p></div><div class="field"><div class="label-line"><label for="field-type">Tipo</label></div><select id="field-type" ${field.name?'disabled':''}>${['Testo','Testo lungo','Immagine','Elenco'].map(t=>`<option ${t===field.type?'selected':''}>${t}</option>`).join('')}</select></div><p id="schema-error" class="field-error" role="alert"></p><p class="hint">Il prototipo conserva la configurazione del modello, ma non genera nuovi campi nel form.</p>`,'<button class="button" data-action="close-dialog">Annulla</button><button class="button primary" data-action="apply-field">Prepara modifica</button>','','field');
}
async function uploadFiles(files){
  if(!files.length)return;
  const accepted=files.filter(f=>['image/jpeg','image/png','image/webp','image/gif'].includes(f.type)&&f.size<=5*1024*1024);
  const rejected=files.length-accepted.length;
  if(rejected)toast(`${rejected} file non accettati: scegli JPG, PNG, WebP o GIF fino a 5 MB.`);
  if(!accepted.length)return;
  state.uploading=true;let container=$('#upload-progress');
  if(!container){toast('Apri la libreria media per caricare un’immagine.');state.uploading=false;return;}
  container.innerHTML=accepted.map(f=>`<div class="upload-row"><strong>${escapeHtml(f.name)}</strong><p>Preparazione locale…</p><progress max="100" value="0" aria-label="Preparazione di ${escapeHtml(f.name)}"></progress></div>`).join('');
  for(let progress=20;progress<=100;progress+=20){await new Promise(r=>setTimeout(r,150));container.querySelectorAll('progress').forEach(el=>el.value=progress);}
  for(const file of accepted){const m={id:crypto.randomUUID(),name:file.name,size:Math.ceil(file.size/1024)+' KB',url:URL.createObjectURL(file),color:''};media.unshift(m);state.selectedMedia=m.id;}
  state.uploading=false;
  if(state.picker)renderPicker();else render();
  toast(`${accepted.length===1?'Immagine pronta':'Immagini pronte'}. Nessun file è stato inviato al server.`);
}
document.addEventListener('input',event=>{
  const el=event.target;
  if(el.dataset.field){page()[el.dataset.field][state.lang]=el.value;updateFromInput();}
  if(el.id==='content-search'){state.filter=el.value;$('#content-results').innerHTML=contentResults();}
  if(el.id==='media-search'||el.id==='picker-search'){state.mediaFilter=el.value;$(el.id==='picker-search'?'#picker-results':'#media-results').innerHTML=mediaGrid(el.id==='picker-search');}
});
document.addEventListener('change',event=>{
  const el=event.target;
  if(el.dataset.settingLang){state.draft.langs=el.checked?['it','en']:['it'];if(!state.draft.langs.includes(state.lang))state.lang='it';renderSavebar();}
  if(el.id==='upload-input'){uploadFiles([...el.files]);el.value='';}
});
document.addEventListener('click',async event=>{
  const button=event.target.closest('[data-action]');if(!button||button.disabled)return;
  event.preventDefault();const {action,id,view,lang,direction}=button.dataset;
  switch(action){
    case 'navigate':navigate(view);break;
    case 'open-page':navigate('edit',id);break;
    case 'language':state.lang=lang;state.invalid=false;render();break;
    case 'jump-features':$('#features-section')?.scrollIntoView({behavior:'smooth',block:'center'});break;
    case 'save':await save();break;
    case 'discard':discard();break;
    case 'confirm-discard':state.draft=clone(state.saved);state.error=null;state.invalid=false;if(!page())state.view='contents';closeDialog(true);render();toast('Modifiche annullate.');break;
    case 'close-dialog':closeDialog();break;
    case 'discard-child':closeDialog(true);break;
    case 'leave-discard':{const next=state.pending;state.pending=null;state.draft=clone(state.saved);closeDialog(true);next();break;}
    case 'leave-save':{const next=state.pending;state.pending=null;closeDialog(true);await save(next);break;}
    case 'new-feature':openFeature();break;
    case 'edit-feature':openFeature(id);break;
    case 'apply-feature':{
      const title=$('#child-title').value.trim();if(!title){$('#child-error').textContent='Inserisci il titolo del servizio.';$('#child-title').setAttribute('aria-invalid','true');$('#child-title').focus();break;}
      const existing=page().features.find(f=>f.id===state.featureId);
      if(existing){existing.title=title;existing.description=$('#child-description').value;}else page().features.push({id:crypto.randomUUID(),title,description:$('#child-description').value,color:'blue'});
      closeDialog(true);render();toast('Servizio applicato alla pagina. Salva per confermare.');break;
    }
    case 'remove-feature':{
      const index=page().features.findIndex(f=>f.id===state.featureId),removed=clone(page().features[index]),pageId=page().id;
      page().features.splice(index,1);closeDialog(true);render();toast('Servizio rimosso dalla pagina. Modifica non ancora salvata.',()=>{const p=state.draft.pages.find(p=>p.id===pageId);if(p&&!p.features.some(f=>f.id===removed.id)){p.features.splice(index,0,removed);render();}});break;
    }
    case 'move-feature':{
      const list=page().features,index=list.findIndex(f=>f.id===id),target=index+Number(direction);
      if(target<0||target>=list.length)break;
      [list[index],list[target]]=[list[target],list[index]];render();$(`[data-action="move-feature"][data-id="${id}"][data-direction="${direction}"]`)?.focus();toast('Ordine aggiornato nella pagina. Salva per confermare.');break;
    }
    case 'media-picker':openPicker();break;
    case 'select-media':state.selectedMedia=id;$('#picker-results').innerHTML=mediaGrid(true);break;
    case 'apply-media':if(state.selectedMedia){page().image=state.selectedMedia;closeDialog(true);render();toast('Copertina selezionata. Salva la pagina per confermare.');}break;
    case 'upload':$('#upload-input').click();break;
    case 'media-detail':{
      const m=media.find(m=>m.id===id);state.selectedMedia=id;
      const uses=state.draft.pages.filter(p=>p.image===id).length;
      openDialog(escapeHtml(m.name),'Dettagli del file',`${artwork(m,'preview-art')}<p style="margin-top:18px">${escapeHtml(m.size)} · ${uses?`Usata in ${uses} contenuti del prototipo.`:'Non usata nei contenuti del prototipo.'}</p><p>Il controllo d’uso copre solo questi dati dimostrativi. Nel prodotto deve includere campi annidati, lingue e riferimenti nei template.</p>`,`<button class="button" data-action="close-dialog">Chiudi</button><button class="button danger" data-action="delete-media" ${uses?'disabled':''}>Elimina file</button>`);break;
    }
    case 'delete-media':openDialog('Eliminare definitivamente il file?','Questa operazione riguarda solo la libreria del prototipo.',`<p><strong>${escapeHtml(media.find(m=>m.id===state.selectedMedia)?.name)}</strong> verrà rimosso. Nel CMS un file eliminato senza cestino non può essere ripristinato dall’interfaccia.</p>`,'<button class="button" data-action="close-dialog" autofocus>Annulla</button><button class="button danger solid" data-action="confirm-delete-media">Elimina file</button>');break;
    case 'confirm-delete-media':{const m=media.find(m=>m.id===state.selectedMedia);if(m?.url)URL.revokeObjectURL(m.url);media=media.filter(m=>m.id!==state.selectedMedia);state.selectedMedia=null;closeDialog(true);render();toast('File eliminato dalla libreria dimostrativa.');break;}
    case 'clear-search':state.filter='';render();$('#content-search')?.focus();break;
    case 'new-page':openDialog('Crea un contenuto','Raccolta Pagine',`<div class="field"><div class="label-line"><label for="new-page-label">Nome del contenuto *</label></div><input type="text" id="new-page-label" placeholder="Es. Chi siamo" autofocus><p class="hint">Inizierai a compilarlo prima di salvarlo. Nel prototipo non viene creato un nuovo template pubblico.</p><p id="new-page-error" class="field-error" role="alert"></p></div>`,'<button class="button" data-action="close-dialog">Annulla</button><button class="button primary" data-action="create-page">Inizia a scrivere</button>');break;
    case 'create-page':{const title=$('#new-page-label').value.trim();if(!title){$('#new-page-error').textContent='Dai un nome al contenuto.';$('#new-page-label').focus();break;}const id=crypto.randomUUID();state.draft.pages.push({id,label:title,title:{it:title,en:''},intro:{it:'',en:''},image:media[0].id,features:[]});state.selectedId=id;state.view='edit';state.lang='it';closeDialog(true);render();$('#page-title')?.focus();break;}
    case 'new-field':openField();break;
    case 'edit-field':openField(id);break;
    case 'apply-field':{const name=$('#field-name').value,label=$('#field-label').value.trim();if(!label||!/^[a-z][a-z0-9_]*$/.test(name)||(!state.fieldId&&state.draft.schema.some(f=>f.name===name))){$('#schema-error').textContent='Inserisci un’etichetta e un nome tecnico valido e univoco.';break;}if(state.fieldId)state.draft.schema.find(f=>f.name===state.fieldId).label=label;else state.draft.schema.push({name,label,type:$('#field-type').value});closeDialog(true);render();toast('Configurazione preparata. Applica le modifiche per salvarla.');break;}
    case 'preview':openDialog('Anteprima indicativa','Mostra le modifiche del modulo, anche quelle non salvate.',`${previewMarkup(true)}<p class="hint" style="margin-top:15px">Questa è una rappresentazione del contenuto. Il rendering fedele dei template richiede un endpoint di anteprima dedicato.</p>`,'<button class="button primary" data-action="close-dialog">Torna alla modifica</button>','wide');break;
    case 'conflict':showConflict();break;
    case 'accept-remote':openDialog('Sostituire le modifiche locali?','Conferma prima di scartare il tuo lavoro.','<p>Nel prototipo verrà caricata una versione simulata con un titolo diverso. Copia prima i tuoi testi se vuoi conservarli.</p>','<button class="button" data-action="conflict" autofocus>Torna al confronto</button><button class="button danger solid" data-action="confirm-remote">Carica versione server</button>');break;
    case 'confirm-remote':state.draft=clone(state.saved);if(page())page().title.it='Un nuovo modo di dare forma alle idee.';state.saved=clone(state.draft);state.error=null;closeDialog(true);render();toast('Versione server simulata caricata.');break;
    case 'copy-draft':{const text=JSON.stringify(state.draft,null,2);try{await navigator.clipboard.writeText(text);toast('Il tuo lavoro è stato copiato.');}catch{const area=document.createElement('textarea');area.value=text;area.setAttribute('aria-label','Copia manualmente il tuo lavoro');$('.dialog-body').append(area);area.select();toast('Copia il testo selezionato con Ctrl/Cmd+C.');}break;}
    case 'reconnect':closeDialog(true);toast('Sessione ripristinata nella simulazione. Puoi salvare.');await save();break;
    case 'guide':openDialog('Un editor che ti accompagna','Percorso consigliato per provare il prototipo.','<ol class="dialog-list"><li>Cambia un titolo: anteprima e stato si aggiornano.</li><li>Modifica o riordina un servizio: resta tutto nella pagina.</li><li>Cambia sezione senza salvare: scegli come proseguire.</li><li>Prova un errore, un conflitto o la sessione scaduta dai controlli in basso a sinistra.</li><li>Apri la libreria e scegli una copertina.</li></ol><p>Nessun dato viene inviato al CMS. Ricaricando la pagina la demo torna allo stato iniziale.</p>','<button class="button primary" data-action="close-dialog">Inizia</button>');break;
    case 'account':openDialog('Il tuo account','Amministratore · sessione dimostrativa','<p>Nel prodotto, qui trovi gestione dell’accesso e uscita. L’uscita deve passare dallo stesso controllo sulle modifiche non salvate.</p>','<button class="button primary" data-action="close-dialog">Chiudi</button>');break;
  }
});
$('#dialog').addEventListener('cancel',event=>{event.preventDefault();closeDialog();});
window.addEventListener('beforeunload',event=>{if(dirty()||state.saving||state.uploading){event.preventDefault();event.returnValue='';}});
document.addEventListener('keydown',event=>{if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='s'){event.preventDefault();if(!$('#dialog').open)save();}});
render();

# Kris 2.0 — piano di azione

Stato: bozza da rivedere. Parte dalla 1.9.0 (branch `refactor01`). Le decisioni segnate «da confermare» non sono ancora state prese.

## 1. Obiettivi

1. Rimettere ordine nel modello dati senza riscriverlo: un solo significato per ogni concetto.
2. Scrivere e leggere i contenuti attraverso un repository, con dietro un file JSON o un database.
3. Rifare l'interfaccia dell'editor su un design system (regole impeccable, layout con Claude Design).
4. Analisi di sicurezza, prima sulla 1.9 e poi sulla superficie nuova.
5. Plugin: estensioni PHP con la loro interfaccia, installabili dall'editor da una repository pubblica.
6. Aggiunte minori raccolte dall'uso sui siti (indirizzi, SEO, strumenti dell'agente, editor).

Vincolo trasversale: Kris resta generico. Entra nel core ciò che serve a qualunque sito e non nomina un fornitore; il resto è un plugin.

## 2. Decisioni prese

| Tema | Decisione |
| --- | --- |
| Sintassi dei template | Non cambia. `{{campo}}`, `k-array`, `k-component` e le condizioni restano validi; la 2.0 aggiunge soltanto. |
| Dati | Formato 2 con migrazione automatica dalla 1.x. |
| Indirizzi | Indirizzi leggibili (`/progetto/1`, `/en/menu`). I vecchi indirizzi con query string rispondono con un 301. |
| Persistenza | Tutti scrivono sul repository; un adapter decide se dietro c'è JSON o database. Sito e plugin non vedono la differenza. |
| Plugin | Solo dalla repository pubblica ufficiale, con indice firmato. Nessun caricamento di zip dall'editor. |
| Contenuto unico | Diventa una proprietà del tipo nello schema, non un'opzione dell'editor. |
| Dati del core | Sono contenuti normali con nome riservato (`kris_…`), schema gestito dal framework. |
| Variabili globali | Ogni tipo a contenuto unico è leggibile da qualunque template come `{{tipo.campo}}`. |
| Multilingua | È un attributo del campo (`localized`), valido per ogni tipo di campo. |
| Gruppo SEO | Entra nella 2.0: titolo, descrizione e immagine per pagina, iniettati da Kris. |
| Utenti | L'editor resta con un solo account, senza ruoli. |
| Slug | Opzionale sul contenuto; senza slug vale l'id. Duplicati gestiti con suffisso. |
| Percorsi del tema | Riscrittura al rendering, niente `<base href>`. |
| PHP | Versione minima 8.3. |
| Nomi riservati | Prefisso `kris_` per tipi, template e pagine. |
| Compatibilità | Circa dieci siti installati, nessuno critico: niente strati di compatibilità a lungo termine. Migrazione dei dati, redirect e un passaggio a mano per sito. |

## 3. Modello dati 2

### 3.1 Vocabolario

| Concetto | Significato | Oggi |
| --- | --- | --- |
| **Tipo** | Lo schema dei campi. Può essere a contenuto unico (`single`) o con molti contenuti. | Una voce di `k_model.json`, più pezzi in `cms_settings.json` |
| **Contenuto** | Un'istanza di un tipo, con id stabile. | Un'entità di `k_data.json` |
| **Lista** | Un campo che contiene un gruppo ripetuto, di proprietà del contenuto. Non ha pagina propria. | Campo `array` |
| **Vista** | Un template che rende un tipo: pagina intera, card, dettaglio. Un tipo ha più viste. | Implicito: template e frammenti `k-template` |
| **Pagina** | Un indirizzo che abbina una vista a un contenuto fisso oppure a ogni contenuto di un tipo. | Un nome in `allowed_pages.json`; `page`, `key` e `id` liberi nell'URL |

Nell'editor il vocabolario per le persone è più semplice e si ricava da questo: un contenuto unico con un indirizzo è una **pagina** (homepage), uno senza è un **blocco** (navbar, piè di pagina), un tipo con molti contenuti è una **raccolta**. La sezione che gestisce le pagine del modello si chiama «Indirizzi».

Due regole:

- Se un elemento ha una pagina propria, è un contenuto di un tipo, non un elemento di lista. Le liste con pagina di dettaglio di oggi (`path=posts/3`) vengono promosse a tipo dalla migrazione.
- «Posts» non è un concetto dei dati: è un modo di presentare nell'editor un tipo con molti contenuti (i nuovi in cima).

### 3.2 Tipi di campo

| Tipo 2.0 | Sostituisce | Note |
| --- | --- | --- |
| `text` | `plain` (`localized: false`) e `text` (`localized: true`) | Testo senza HTML |
| `richtext` | `richtext` | HTML, da sanitizzare al salvataggio (vedi sicurezza) |
| `media` | `image` | Immagini, video e PDF: il nome attuale è fuorviante |
| `select` | stringhe di tag con `has` | Opzioni definite nello schema, scelta singola o multipla (`multiple`) |
| `boolean` | — | Interruttore. Serve la condizione `{{#if campo}}` |
| `list` | `array` | Gruppo ripetuto con schema `of` |

Ogni campo ha `localized` (vero o falso): con `false` il valore è uno solo per tutte le lingue, con `true` uno per lingua. Vale anche per `boolean` e `select`.

Fuori perimetro salvo richiesta: `date`, `number`, relazioni fra contenuti.

### 3.3 Forma dei dati

Il tipo del campo sta solo nello schema, non più ripetuto in ogni valore. Schizzo indicativo, da fissare nella specifica:

```json
{
  "types": {
    "progetti": {
      "label": "Progetti",
      "description": "I lavori in portfolio",
      "single": false,
      "editor": {"newest_first": true},
      "fields": [
        {"name": "title", "type": "text", "localized": true, "label": "Titolo"},
        {"name": "category", "type": "select", "localized": false,
         "options": [{"value": "web", "label": {"it": "Web", "en": "Web"}}]},
        {"name": "featured", "type": "boolean", "localized": false},
        {"name": "gallery", "type": "list", "of": [
          {"name": "photo", "type": "media", "localized": false}
        ]}
      ]
    }
  },
  "pages": {
    "homepage": {"template": "homepage", "type": "homepage", "path": "/"},
    "progetto": {"template": "progetto", "type": "progetti", "path": "/progetto/{id}", "sitemap": true}
  }
}
```

```json
{
  "type": "progetti", "id": 3, "rev": 7, "hidden": false,
  "values": {
    "title": {"it": "Casa Rossi", "en": "Rossi House"},
    "category": "web",
    "featured": true,
    "gallery": [{"id": 0, "values": {"photo": "assets/uploads/casa.webp"}}]
  }
}
```

Cosa confluisce nello schema: `k_model.json`, le chiavi `collections`, `collection_order` e `post_collections` di `cms_settings.json`, `config/allowed_pages.json`, le regole della sitemap di `config/seo.json`.

### 3.4 Tipi riservati del core

Sono tipi a contenuto unico con prefisso `kris_`. Lo schema lo gestiscono le migrazioni, i valori li compila il cliente. L'editor li elenca in un gruppo «Sito» senza codice dedicato per ciascuno.

| Tipo | Contenuto | Come arriva sul sito |
| --- | --- | --- |
| `kris_site` | Nome del sito, indirizzo pubblico, favicon, modello del titolo («{pagina} \| {sito}»), immagine di condivisione e descrizione predefinite, codici di verifica (Search Console, Bing), dati dell'attività (nome, logo, indirizzo, telefono, orari, social), righe aggiuntive di `robots.txt` | `{{kris_site.name}}`, `{{site_url}}`; favicon, titolo, meta, `og:locale` e JSON-LD dell'attività iniettati nell'head |
| `kris_legal` | Titolare, informative, testi del banner e delle categorie cookie | Pagine `privacy` e `cookie` del tema; banner con `k-component` |
| `kris_notfound` | Testi e immagine della 404 | `template/404.html` del tema o pagina di serie |
| `kris_wip` | Interruttore «sito in lavorazione» più i campi scelti dal tema | `template/kris_wip.html` del tema o pagina di serie |

`kris_wip` è l'unico tipo riservato con schema personalizzabile: il campo `enabled` è del framework, gli altri li definisce il tema da Struttura. Con l'interruttore acceso:

- i visitatori ricevono la pagina di lavorazione con stato 503 e `Retry-After`, sitemap compresa;
- chi ha la sessione dell'editor aperta vede il sito normale, con una barra che ricorda che è in lavorazione;
- `/kris-admin/` e l'endpoint dell'agente restano raggiungibili.

Regola sull'iniezione: Kris inietta nell'`<head>` solo se il template non ha già quel tag (favicon, canonical, hreflang, tag del gruppo SEO, JSON-LD). Nel corpo della pagina non aggiunge elementi; l'unica eccezione sono gli attributi delle immagini ottimizzate (vedi 3.7), e solo se il tag non li ha già.

I dati dell'attività generano un JSON-LD schema.org generico (`Organization` o `LocalBusiness` con il sottotipo scelto). Un template che ha già un suo JSON-LD dell'attività lo disattiva.

`config/` resta solo per ciò che è privato o tecnico: credenziali, agente, aggiornamenti, connessione al database.

### 3.5 Aggiunte ai template

Tutte additive.

- `{{tipo.campo}}` per i tipi a contenuto unico, anche nelle condizioni. Nomi in `snake_case`. Nessun accesso per indice alle liste: per quelle globali si usa `k-array="tipo.lista"`.
- `{{#if campo}}` per i booleani; `has` continua a funzionare e vale anche per i `select` multipli.
- `{{link page="menu"}}` e `{{link page="progetto" id=id}}`: costruiscono l'indirizzo con la lingua corrente.
- `{{site_url}}` e `{{canonical_url}}`.
- `template/kris_head.html`: frammento globale inserito in ogni pagina prima di `</head>`. È anche il punto in cui i plugin aggiungono i loro tag.
- `k-component` senza `k-index` per i tipi a contenuto unico.

### 3.6 Gruppo SEO per pagina

Attivabile sui tipi che hanno una pagina: aggiunge titolo, descrizione, immagine e canonical personalizzato nell'editor; Kris inietta `<title>` (con il modello di `kris_site`), description, canonical e tag Open Graph se il template non li ha. Index o noindex si sceglie sull'indirizzo (sezione 4). I siti che usano campi propri (`meta_title`) continuano a funzionare.

### 3.7 Immagini ottimizzate

Le foto caricate sono spesso troppo pesanti. Al caricamento di un'immagine Kris genera più larghezze in WebP accanto all'originale (per esempio 480, 960, 1600 px, mai oltre la larghezza originale). Il cliente carica come sempre: niente da scegliere.

- Al rendering, un `<img>` che punta a un file con varianti riceve `srcset`, `width` e `height`, se il tag non li ha già; `sizes` resta del tema, con un valore predefinito.
- Lo stesso strumento si può avviare in qualunque momento su un'immagine già in libreria, e su tutta la libreria in una volta: le varianti si generano sul posto e l'indirizzo del file non cambia.
- Senza GD o senza supporto WebP le varianti non si generano e il tag resta com'è.
- Le varianti seguono l'originale: eliminando il file si eliminano anche loro, e non compaiono come file separati in libreria.
- Valgono anche per i caricamenti dell'agente e dello staging.

## 4. Indirizzi

- Ogni pagina dichiara template, tipo, indirizzo, presenza in sitemap, priorità nella sitemap e `noindex`. Tutto si cambia dall'editor e dall'agente.
- Lingua: la principale senza prefisso (`/menu`), le altre con prefisso (`/en/menu`). La lingua principale si sceglie in Impostazioni › Lingue e decide anche l'`x-default` degli hreflang, il canonical e `og:locale`.
- Sitemap: generata dalle pagine, con `lastmod` preso dall'ultima modifica del contenuto e le alternative per lingua (già presenti nella 1.9).
- `robots.txt`: generato da Kris con l'indirizzo assoluto della sitemap e `Disallow: /kris-admin/`, più le righe aggiuntive scritte in `kris_site`. Un `robots.txt` fisico nella root continua a vincere.
- Anteprima: `/kris-admin/preview/` seguito dall'indirizzo pubblico (`/kris-admin/preview/en/menu`, `/kris-admin/preview/lavagna-7k3q`) mostra quella pagina con le modifiche in attesa. Si scrive anche a mano, quindi raggiunge le pagine non collegate da nessun link; richiede la sessione dell'editor, altrimenti risponde 404; i link interni restano nell'anteprima. Sostituisce il parametro `kris_preview=1`, che si perde scrivendo un indirizzo a mano. «Indirizzi», «Da pubblicare» e lo stato dello staging dell'agente danno il link di anteprima di ogni pagina.
- Editor: si sposta da `/editor/` a `/kris-admin/`, così `editor` torna un nome libero per le pagine dei siti. Nessun reindirizzamento dal vecchio indirizzo: i siti sono pochi. L'aggiornamento alla 2.0 fa tutto da solo: crea lo stub `kris-admin/`, elimina `editor/`, aggiorna l'indirizzo dell'editor salvato in `config/auth.php` (usato per i link di reset della password) e, a installazione finita, porta il browser al nuovo indirizzo.
- Dettaglio: `/progetto/{id}` oppure, se il contenuto ha uno slug, `/progetto/casa-rossi`. Vedi «Slug» qui sotto.
- Nomi riservati rifiutati alla creazione di una pagina: `kris`, `kris-admin`, `assets`, `data`, `config`, `template`, `sitemap`, i codici lingua attivi e i nomi dei file nella root.
- Percorsi relativi: con indirizzi a più livelli `assets/...` non si risolve più dalla root. Kris riscrive al rendering gli attributi `href`, `src`, `srcset`, `poster` e gli `url()` negli attributi `style` che iniziano con `assets/`, `kris/` o `index.php`, anteponendo la root del sito. Non si usa `<base href>`, che romperebbe i link interni alla pagina (`#contatti`). Per gli indirizzi costruiti nel JavaScript del tema c'è `{{base_path}}`. Va provato in sottocartella.
- Redirect: tabella gestibile dall'editor. Kris ne aggiunge uno da solo quando cambia l'indirizzo di una pagina.
- Vecchi indirizzi: `?page=…&key=…&id=…` e `path=lista/ID` rispondono con 301 al nuovo indirizzo, con la mappa registrata dalla migrazione.
- Lingua automatica: opzione nelle impostazioni. Lato server da `Accept-Language`, solo quando l'URL non indica una lingua, scelta salvata in un cookie, crawler esclusi.
- Template riservati: tutto ciò che inizia con `kris_` è riservato, tipi, template e nomi di pagina. `template/kris_head.html` è l'head comune, `template/kris_wip.html` la pagina dei lavori in corso; `template/404.html` resta per compatibilità.
- Senza riscrittura sul server restano validi gli indirizzi con query string. Su Apache il `.htaccess` esiste già; per Nginx va documentata la regola.

### Slug

Un tipo non sa se ha una pagina di dettaglio, quindi lo slug non è un campo dello schema. È un attributo di sistema del contenuto, come `id`, `hidden` e `rev`: opzionale e per lingua.

- Lo usa la pagina: se l'indirizzo della pagina prevede il contenuto (`/progetto/{…}`), Kris mette lo slug quando c'è e l'id quando manca.
- Nell'editor il campo «Indirizzo» compare nella modifica di un contenuto solo se almeno una pagina punta al suo tipo. Si può proporre in automatico da un campo indicato nella pagina (di solito il titolo).
- Unicità per tipo e lingua. Un duplicato riceve un suffisso (`casa-rossi-2`) e l'editor lo segnala.
- Uno slug solo numerico non è ammesso: sarebbe ambiguo con l'id.
- Risoluzione di `/progetto/x`: se `x` è un numero è un id (e risponde con 301 allo slug, se esiste); altrimenti si cerca lo slug nella lingua corrente; poi fra gli slug precedenti del contenuto, con 301 a quello attuale.
- Il contenuto conserva i propri slug precedenti: una rinomina non rompe i vecchi indirizzi e non serve una riga nella tabella dei redirect.
- Il repository deve offrire la ricerca per slug.

## 5. Repository e adapter

Un'unica interfaccia per tutto ciò che legge e scrive contenuti: sito pubblico, editor, staging, agente, migrazioni, plugin.

Garanzie uguali per entrambi gli adapter:

- lettura per tipo e id; elenco con ordinamento e filtri semplici sui campi;
- scrittura con controllo di revisione (`rev`): due salvataggi concorrenti non si sovrascrivono in silenzio;
- operazione atomica su più contenuti;
- esportazione e importazione dell'intero stato, per staging, snapshot degli aggiornamenti e passaggio da un adapter all'altro.

Adapter:

- **JSON** (predefinito): file con lock e scrittura atomica, come oggi `JsonStore`. Corretto, lento con molte scritture.
- **Database** (PDO, SQLite e MySQL): un contenuto per riga, valori in una colonna JSON, colonne indicizzate per tipo, id e ordine. Niente tabelle per campo.

La scelta sta in `config/` (file privato). Uno strumento converte un sito da JSON a database e viceversa usando esportazione e importazione.

I plugin dichiarano tipi propri privati (prefisso `plg_<nome>_`) e li usano attraverso il repository. Un plugin può segnalare nel manifest che il database è consigliato.

Lavoro di refactoring: oggi `Entity` riceve il nome del file e circa 130 punti in 33 file leggono o scrivono direttamente i JSON (`Staging`, `Operations`, `Migrator`, `installer.php`, `public.php`, editor). Vanno portati tutti dietro l'interfaccia prima di aggiungere il secondo adapter.

## 6. Plugin

### 6.1 Pacchetto

Zip firmato con: manifest, codice PHP, pannelli dell'editor, asset pubblici (JS e CSS per il sito), migrazioni dei propri tipi.

Il manifest dichiara: nome, versione, versione minima di Kris, estensioni PHP richieste, tipi privati, agganci usati, voci dell'interfaccia, cookie e fornitori esterni, database consigliato o no.

### 6.2 Interfaccia

Due livelli:

- **Dichiarativo**: il plugin descrive le impostazioni come campi (gli stessi tipi dei contenuti) e l'editor genera il pannello. Conforme allo stile per costruzione.
- **Vista propria**: un file PHP che compone i componenti del kit dell'editor (partial e classi documentati). Per interfacce che un modulo non copre, come un calendario.

Punti di aggancio nell'editor: voce nella barra laterale, scheda nelle impostazioni, riquadro nella dashboard, tipo di campo nuovo, azione su un contenuto. Vedi `editor-specifiche.md`.

### 6.3 Agganci del core

Elenco iniziale, ricavato dai primi casi d'uso:

- tag nell'`<head>` e script a fine pagina, con la categoria di consenso;
- evento «pubblicato» e «contenuto salvato»;
- endpoint HTTP propri (per un modulo contatti o un booking), con CSRF e limiti di frequenza forniti dal core;
- registro dei cookie: ogni plugin dichiara cookie, durata e fornitore; il core compone la tabella per la cookie policy;
- tipi di campo e viste dell'editor.

### 6.4 Installazione e sicurezza

- Fonte unica: la repository pubblica, con indice firmato Ed25519 come `releases.json`. La revisione di ciò che entra nella repo è il confine di sicurezza.
- Installa, aggiorna, disattiva e rimuove solo l'admin dall'editor. L'agente non può installare né attivare plugin.
- Un errore fatale in un plugin non deve rendere inaccessibile l'editor: gli agganci sono isolati e un file in `config/` disattiva tutti i plugin (modalità sicura).
- Su hosting condiviso non esiste una sandbox PHP: un plugin installato ha gli stessi permessi di Kris. Va scritto chiaramente nell'editor al momento dell'installazione.

### 6.5 Plugin di prima parte

Servono da banco di prova del contratto:

1. **Misurazione**: GA4 e Pixel con Consent Mode v2, eventi di contatto (clic su `tel:`, `wa.me`, mappe).
2. **Cookie policy automatica**: tabella generata dal registro dei cookie.
3. **IndexNow**: avviso ai motori di ricerca alla pubblicazione.

## 7. Editor

Rifacimento dell'interfaccia su un design system, mantenendo PHP renderizzato sul server, JS vanilla e nessun passo di build. Il dettaglio funzionale è in `editor-specifiche.md`.

Ordine: token e componenti, poi le viste, poi gli slot per i plugin.

## 8. Sicurezza

Prima analisi sulla 1.9, con correzioni rilasciate come 1.9.x. Punti già individuati leggendo il codice:

- `data/` e `config/` sono protetti solo da `.htaccess`: su Nginx o con `AllowOverride None` i JSON sono scaricabili. Valutare lo spostamento fuori dalla root pubblica o file con estensione `.php` e intestazione di blocco.
- `richtext` esce senza sanitizzazione. L'unico autore umano è l'admin, ma scrivono `richtext` anche l'agente e, nella 2.0, i plugin.
- L'account è uno solo e resta così: la sua compromissione dà accesso a tutto, plugin compresi. Vanno quindi curati limiti di frequenza, recupero della password e conferma della password per le azioni più delicate (installare un plugin).
- TinyMCE è caricato da una CDN esterna nell'editor autenticato.
- Da verificare: limiti di frequenza sul login e sul reset, fiducia in `X-Forwarded-Proto` e `Host`, parametri del cookie di sessione, intestazioni di sicurezza dell'editor.

Seconda analisi prima del rilascio della 2.0 su: endpoint dei plugin, installazione dei plugin, adapter database (query parametrizzate), routing e redirect (redirect aperti), accesso con punto ai tipi globali.

## 9. Aggiornamento dei siti dalla 1.x

L'updater della 1.9 rifiuta per progetto le versioni con major diversa, sia dal canale sia con lo zip caricato a mano (`Updater.php`, righe 113 e 332). Quindi un sito 1.9 non può installare la 2.0 dall'editor così com'è.

Percorso proposto:

1. **1.10 «ponte»**, ultima della serie 1.x, installabile dall'editor da qualunque 1.x:
   - accetta la 2.0 quando la release la dichiara installabile da `min_from: 1.10.0`;
   - aggiunge un controllo di compatibilità che elenca, prima di installare, cosa cambierà sul sito: liste da promuovere a tipo, nomi di pagina riservati, template con link scritti a mano, versione di PHP, riscrittura degli indirizzi disponibile.
2. **2.0** dall'editor: l'installer arriva dentro il pacchetto nuovo, quindi esegue la migrazione al formato 2 con snapshot e ripristino automatico come oggi.
3. **Passaggio a mano per sito**: revisione dei template (link, liste promosse), `.htaccess` se il sito ne ha uno proprio, prova delle pagine.

Resta sempre possibile l'aggiornamento via FTP: si sostituisce `kris/` e l'editor propone la migrazione.

Estensioni necessarie al contratto delle migrazioni: oggi sono funzioni pure su `k_data`, `k_model` e `cms_settings`; la migrazione al formato 2 deve leggere anche `config/allowed_pages.json` e `config/seo.json`.

## 10. Fasi

| Fase | Contenuto | Esce come |
| --- | --- | --- |
| 0 | Bug segnalati, strumenti dell'agente, prima analisi di sicurezza e correzioni | 1.9.x |
| 1 | Specifica: formato 2, pagine e indirizzi, API del repository, contratto dei plugin | Documento |
| 2 | Repository con adapter JSON; tutto il codice dietro l'interfaccia; test verdi senza cambiare il formato | Interno |
| 3 | Formato 2 e migrazione; tipi di campo nuovi; tipi riservati; accesso con punto | Interno |
| 4 | Pagine, indirizzi leggibili, redirect, `{{link}}`, variabili globali, head comune, lingua automatica, lavori in corso | Interno |
| 5 | Adapter database e conversione JSON ↔ database | Interno |
| 6 | Design system e rifacimento delle viste dell'editor | Interno |
| 7 | Plugin: caricamento, agganci, pannelli, installazione dalla repo; tre plugin di prima parte | Interno |
| 8 | Seconda analisi di sicurezza; 1.10 ponte; migrazione dei dieci siti; rilascio | 1.10, 2.0.0 |

La fase 6 può procedere in parallelo alle fasi 3–5 una volta chiusa la specifica.

### Fase 0 in dettaglio

Bug:

- `register_page` con `sitemap: false` aggiunge comunque la pagina alla sitemap (`Operations.php:744`, `isset` è vero anche per `false`).
- Falso positivo «il template usa ancora il campo» (`Staging.php:451`): il controllo guarda solo il nome del campo. Non basta declassare ad avviso quando un'altra raccolta ha un campo omonimo: passerebbero anche i casi veri (`homepage` e `navbar` hanno entrambe `page_title`). Per la 1.9.x va ricavato quale raccolta rende ogni template, dai riferimenti `k-component` e `k-array`, dalle regole della sitemap e dai campi che il template usa. La correzione completa arriva con le pagine legate a un tipo.

Strumenti dell'agente (aggiunte, richiedono lavoro anche in `kris-agent`):

- lettura di un file per righe e ricerca di testo nel tema;
- impronta di un file senza leggerlo, oppure `edit_file` senza impronta quando il testo da sostituire è unico;
- HTML finito di una pagina in staging, compreso ciò che Kris inietta;
- lettura di sitemap generata, `robots.txt` e configurazione SEO;
- una sola approvazione degli script quando la stessa modifica tocca più file;
- operazione per mettere o togliere una pagina dalla sitemap;
- link di anteprima per ogni pagina e contenuto toccati nello stato dello staging, e anteprima che resta attiva anche scrivendo l'indirizzo a mano;
- caricamento su un indirizzo del dominio del sito invece che su `workers.dev`, bloccato da alcuni ambienti;
- elenco dei file caricati non più usati da nessun contenuto, e loro eliminazione.

Da verificare sulla 1.9.0: `add_item` con campi fuori da `fields` o con liste annidate dentro `fields` crea elementi vuoti senza errore. La 1.9.0 rifiuta le chiavi sconosciute; se la segnalazione viene da un sito non aggiornato è già risolta, altrimenti va corretta.

## 11. Impatto fuori da questa repository

- **`kris-agent`**: le operazioni dello staging cambiano con il formato 2 (tipi, pagine, campi nuovi). Il Worker deve riconoscere la versione del sito.
- **Skill `kris-dev`** e `AGENTS.md`: da riscrivere per il modello 2. Nella skill c'è anche un refuso segnalato (sezione 3, riga sul JS: i due esempi dell'escape sono identici).
- **Siti installati**: su lettera32bar.it la pagina `lavagna-7k3q` va tolta a mano da `config/seo.json`; istruzioni inviate a Fabrizio il 18 novembre 2026.

## 12. Punti aperti

1. Versione di PHP sui siti installati: la 2.0 richiede 8.3, va verificata sugli hosting prima della migrazione. Il controllo di compatibilità della 1.10 lo segnala.

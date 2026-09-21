# Guida operativa per lavorare con Kris

Queste istruzioni valgono per tutta la repository e per gli agenti che la modificano. Il caso d'uso principale è trasformare un design Figma o un sito statico in un sito gestibile con Kris e pubblicabile su hosting PHP. Questa è la fonte unica delle istruzioni; `CLAUDE.md` rimanda qui.

## Regole di lavoro

- Leggi il codice coinvolto prima di modificarlo. Le funzionalità descritte qui sono quelle implementate, non una roadmap.
- Controlla `git status` e preserva le modifiche già presenti, specialmente in `data/` e negli upload. Non ripristinare i dati del cliente con quelli dimostrativi.
- Implementa il sito nei file effettivamente serviti. Non creare cartelle di prototipi, report o piani se non richiesti.
- Per una conversione grafica lavora principalmente su `template/`, `assets/`, schema, dati iniziali e whitelist. Cambia `core/` o `editor/` solo se serve una funzionalità non supportata e il compito lo richiede.
- Conserva nomi dei campi e ID esistenti. Una rinomina è una migrazione dei dati e dei template, non una semplice modifica di etichetta.
- Non introdurre framework frontend o dipendenze di build senza una necessità concreta. Il risultato deve funzionare con il rendering PHP di Kris.
- Alla consegna indica cosa è stato implementato, quali verifiche sono passate e quali integrazioni restano effettivamente mancanti.

## Come funziona il framework

Kris è un CMS PHP basato su file JSON, senza database. Il sito pubblico è renderizzato sul server; l'editor modifica gli stessi dati letti dal sito. Il salvataggio rende immediatamente visibile il contenuto: non esistono stati bozza/pubblicato, autosave o versionamento collaborativo.

| Percorso | Responsabilità |
| --- | --- |
| `index.php` | Entry point pubblico: valida pagina e lingua, carica l'entità, renderizza il template. |
| `template/*.html` | Pagine complete e frammenti HTML di liste e componenti. |
| `assets/css/`, `assets/js/`, `assets/images/`, `assets/fonts/` | Stili, interazioni e risorse del tema. Crea le sottocartelle solo quando servono. |
| `assets/uploads/` | File editoriali caricati dal CMS, da preservare nei deploy successivi. |
| `data/k_model.json` | Oggetto che associa ogni raccolta allo schema dei suoi campi. |
| `data/k_data.json` | Lista delle entità con i valori effettivi dei contenuti. |
| `data/cms_settings.json` | Lingue abilitate; opzionale, default `it` e `en`. |
| `data/backups/` | Copie precedenti prodotte dalle scritture tramite `JsonStore`. |
| `config/allowed_pages.json` | Nomi dei template autorizzati come pagine pubbliche. |
| `config/auth.php` | Credenziali locali con password sotto forma di hash; generato dal setup, escluso da Git. |
| `core/entity/` | `Entity`, repository JSON, scritture atomiche e gestione dei media. |
| `core/template/` | Interpolazione, condizioni, array, componenti e parsing DOM. |
| `core/scripts/script.js` | Utility esistenti per cambio lingua e filtro degli elementi. |
| `editor/` | Autenticazione, azioni, viste, partial, CSS e JS del pannello. |
| `tests/` | Test PHP e snapshot pubblici con dati isolati in fixture. |
| `vendor/` | Autoloader Composer generato; necessario all'esecuzione. |

`Entity` legge i valori e risolve le traduzioni. `JsonRepository` recupera le entità per nome e ID. `JsonStore` verifica il JSON, scrive tramite file temporaneo e conserva copie precedenti; non garantisce il rilevamento di conflitti tra editor concorrenti. Per scritture applicative usa questo servizio, senza introdurre un secondo sistema di persistenza.

Il rendering esegue prima variabili e condizioni, poi gli array, poi i componenti. Ogni frammento viene renderizzato con la propria entità. Il frontend legge i tipi presenti nei dati: modificare soltanto lo schema non converte automaticamente i dati già salvati.

## Da Figma o HTML statico a un sito Kris

1. **Leggi il materiale di partenza.** Identifica pagine, componenti condivisi, breakpoint, font, colori, spaziature, immagini e stati delle interazioni. Se il file Figma non è accessibile, richiedi il link accessibile o gli export necessari; non dichiarare di averlo consultato. Non installare strumenti o dipendenze solo perché il progetto nasce da Figma.
2. **Mappa contenuti e navigazione.** Per ogni pagina identifica template, raccolta, ID e URL. Elenca i campi che il cliente deve poter modificare e le liste che deve poter aggiungere, eliminare e riordinare.
3. **Costruisci il tema reale.** Traduci il design in HTML semantico, CSS responsive e JS per le interazioni. Mantieni identità grafica, tipografia e gerarchia del riferimento; non imporre al sito pubblico il design dell'editor. Usa token CSS per i valori ricorrenti e componenti per gli elementi condivisi.
4. **Definisci schema e contenuti insieme.** Ogni campo modificabile deve avere una definizione in `k_model.json`, un valore compatibile in `k_data.json` e un utilizzo nel template. Usa descrizioni comprensibili nell'editor. Inizializza le lingue previste con contenuti reali o chiaramente provvisori.
5. **Collega il markup a Kris.** Sostituisci i contenuti editoriali con `{{campo}}`, estrai le liste ripetute in frammenti `k-array` e le sezioni condivise in `k-component`. Rimuovi le card statiche di esempio dai contenitori delle liste.
6. **Completa i flussi.** Menu mobile, modali, accordion, filtri, CTA e link devono funzionare anche con tastiera e contenuti di lunghezza diversa. Gestisci liste vuote, immagini mancanti e testi lunghi. I moduli contatto richiedono un backend esplicito: Kris non offre già un servizio email, prenotazioni, pagamenti o ricerca server.
7. **Verifica sito ed editor insieme.** Modifica un testo, un'immagine e l'ordine di una lista nell'editor e verifica l'effetto sul sito pubblico. Confronta le pagine renderizzate con il riferimento desktop e mobile.
8. **Prepara il deploy.** Applica le verifiche di pubblicazione qui sotto. Pubblica sull'ambiente richiesto solo nell'ambito dell'autorizzazione ricevuta; una richiesta di conversione non identifica da sola un server di destinazione.

### Cosa rendere modificabile

| Elemento del design | Modellazione consigliata |
| --- | --- |
| Titoli, descrizioni, testo dei pulsanti, alt delle immagini | Campi `text` multilingua. |
| Testo con paragrafi, elenchi e link editoriali | `richtext`, soltanto dove è previsto HTML. |
| Immagini e documenti sostituibili | `image`; alt e didascalia sono campi separati. |
| Valore condiviso tra lingue, URL comune, tag, prezzo | `plain` con valore scalare. Usa `text` se anche il valore deve cambiare per lingua. |
| Card, servizi, FAQ o testimonianze appartenenti a una pagina | `array` con schema `of`. |
| Articoli o progetti indipendenti con pagina di dettaglio | Raccolta di entità root, referenziata da un array globale. |
| Navigazione, footer e contenuti riutilizzati su più pagine | Raccolta dedicata con entità condivisa e `k-component`. |
| Griglie, spaziature, breakpoint, decorazioni e animazioni | HTML/CSS/JS del tema; non campi editoriali generici. |

Gli unici tipi implementati sono `plain`, `text`, `richtext`, `image`, `array`. Non inventare tipi come `boolean`, `select`, `date` o relazioni senza implementarne anche editor e rendering. Usa nomi tecnici stabili in `snake_case` con lettere minuscole, numeri e underscore. Non usare `id` e `language` come nomi di campi: sono esposti dal motore.

## Esempio completo: pagina con servizi

In un sito nuovo, questo è un esempio minimo di `data/k_model.json`. Su un sito esistente integra le raccolte senza sostituire l'intero archivio.

```json
{
  "homepage": [
    {"name": "page_title", "type": "text", "description": "Titolo principale"},
    {"name": "services", "type": "array", "description": "Servizi in evidenza", "of": [
      {"name": "title", "type": "text", "description": "Nome del servizio"},
      {"name": "description", "type": "text", "description": "Descrizione breve"}
    ]}
  ]
}
```

I dati corrispondenti in `data/k_data.json` sono una lista, non un oggetto indicizzato per raccolta:

```json
[
  {
    "name": "homepage",
    "id": 0,
    "data": [
      {"name": "page_title", "type": "text", "value": {"it": "Progettiamo il tuo futuro", "en": "Designing your future"}},
      {"name": "services", "type": "array", "value": [
        {"id": 0, "data": [
          {"name": "title", "type": "text", "value": {"it": "Design", "en": "Design"}},
          {"name": "description", "type": "text", "value": {"it": "Siti su misura", "en": "Tailored websites"}}
        ]}
      ]}
    ]
  }
]
```

Gli ID root sono univoci nella raccolta; gli ID dei figli nella singola lista. L'ordine è la sequenza degli elementi nel JSON, non il valore numerico degli ID. Riordinare non deve rinumerarli. Ogni campo contiene `name`, `type` e `value`; `text`, `richtext` e `image` usano valori per lingua, `plain` uno scalare, `array` una lista di figli.

`template/homepage.html`:

```html
<!doctype html>
<html lang="{{language}}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{page_title}}</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
  <main>
    <h1>{{page_title}}</h1>
    <div class="services-grid" k-array="services" k-template="service-card"></div>
  </main>
</body>
</html>
```

`template/service-card.html`:

```html
<article class="service-card">
  <h2>{{title}}</h2>
  <p>{{description}}</p>
</article>
```

La griglia resta nel template della pagina; il frammento contiene una sola card. Gli stili sono in `assets/css/style.css`. Autorizza `homepage` in `config/allowed_pages.json`; il frammento `service-card` non va nella whitelist. L'URL è `index.php?page=homepage&key=homepage&id=0&ln=it`.

## Contratto dei template

- `{{campo}}` legge dall'entità corrente. `{{id}}` è l'ID corrente e `{{language}}` la lingua attiva. Non sono supportati filtri tipo Twig, accessi `parent.title`, espressioni JavaScript o loop `for`.
- I valori `text`, `plain` e `image` vengono escapati per HTML, comprese le virgolette negli attributi. `richtext` emette HTML senza sanitizzazione: usalo soltanto nel corpo della pagina con contenuti fidati, mai in attributi, script o CSS. L'escaping HTML non valida gli schemi degli URL: controlla gli URL editoriali e non interpolare valori dentro codice JavaScript.
- Per una traduzione vuota o assente, `Entity` cerca il primo valore non vuoto disponibile. La stringa `"0"` è valida. Le lingue si configurano in `cms_settings.json` tramite l'editor; disabilitarne una conserva i valori già salvati.
- `k-array="services"` cerca prima un campo array dell'entità corrente. Se il campo non esiste, cerca una raccolta root omonima; un array locale vuoto resta vuoto. Il contenitore resta nel DOM e i frammenti vengono aggiunti al suo contenuto: deve essere inizialmente vuoto.
- Inserisci gli array annidati nel frammento del padre, ciascuno con il proprio `k-template`. Non scrivere il markup dei figli direttamente nel contenitore della pagina: le variabili verrebbero risolte nel contesto sbagliato.
- Un componente, ad esempio `<div k-component="navbar" k-template="navbar" k-index="0"></div>`, carica l'entità root `navbar` con ID `0`. Il segnaposto viene **sostituito** dal frammento: metti tag semantico, classi e attributi sul markup di `template/navbar.html`, non sul segnaposto. Crea anche schema e dati della raccolta condivisa.
- Un frammento può contenere più elementi fratelli. Non introdurre inclusioni ricorsive o cicliche tra componenti e liste.
- Le condizioni supportano `#if`, `#elif`, `#else`, `/if` e gli operatori `==`, `!=`, `>`, `<`, `>=`, `<=`, `has`. `has` controlla un valore in una stringa di tag separati da virgole. Per campi opzionali inizializza il valore e verifica il risultato con campo vuoto e assente.

```html
{{#if category == "featured"}}
  <span class="badge">In evidenza</span>
{{#elif category == "new"}}
  <span class="badge">Novità</span>
{{#else}}
  <span class="badge">Progetto</span>
{{/if}}
```

## Pagine, collegamenti e asset

Una pagina pubblica richiede tre cose: template esistente, nome autorizzato in `config/allowed_pages.json` ed entità esistente. `page` sceglie il template; `key` la raccolta; `id` l'entità. I default sono `homepage`, `homepage`, `0`.

- Root: `index.php?page=project-detail&key=projects&id=3&ln=it`.
- Figlio: `index.php?page=detail&key=homepage&id=0&path=services/0&ln=it`.
- Più livelli: `path=services/0/highlights/2`. Il percorso alterna nome array e ID figlio.

Mantieni la lingua nei link con `ln={{language}}` e usa `&amp;` tra parametri negli attributi HTML. Nel frammento di un figlio `{{id}}` indica il figlio, non il padre: non usarlo come ID root. In un contesto riutilizzabile modella un URL esplicito o fornisci il contesto necessario; non presumere variabili parent inesistenti.

Kris non configura automaticamente slug o rewrite per URL puliti. Usa le route query string esistenti finché non viene richiesto e implementato un routing diverso. La whitelist riguarda le pagine navigabili, non tutti i frammenti HTML.

I percorsi delle risorse si risolvono rispetto all'URL pubblico, non alla cartella `template/`. Usa `assets/...` per l'installazione corrente e verifica anche il deploy in sottocartella; `/assets/...` punta invece alla radice del dominio. Non lasciare link a file locali, export Figma o URL temporanei di preview.

Esporta immagini e icone necessarie dal design, preserva le proporzioni, ottimizza peso e dimensioni e usa font disponibili per il progetto. Gli SVG fidati del tema possono essere asset statici verificati. L'upload editoriale accetta JPG/JPEG, PNG, GIF, WebP, AVIF e PDF fino a 8 MB; non accetta nuovi SVG. Non aggirare `MediaStore` per renderli caricabili.

## Editor e persistenza

`editor/index.php` gestisce sessione, autenticazione e dispatch; `actions.php` le mutazioni; `helpers.php` schema e percorsi; `views/` le pagine; `partials/` gli elementi condivisi. `scripts.js` gestisce i flussi UI e `structure.js` la modifica dello schema.

Mantieni autenticazione e CSRF per ogni mutazione. I salvataggi asincroni confermano il risultato del server prima di mostrare successo; gli errori devono conservare i campi compilati. Il riordino salva senza ricarica e senza spostare lo scroll, anche con testi non ancora salvati. Non annidare form HTML: i controlli delle liste usano form separati associati tramite l'attributo `form`.

L'editor permette di configurare anche gli schemi `of` e presenta l'impatto delle modifiche distruttive. I nomi dei campi devono continuare a corrispondere ai template. Non introdurre messaggi che promettono bozze, undo o gestione conflitti non implementati.

## Avvio, verifica e deploy

Esegui i comandi dalla radice del progetto:

```sh
composer install
php -S 127.0.0.1:8000 -t .
# In un altro terminale:
php tests/run.php
```

Apri `http://127.0.0.1:8000/` e `/editor/`. Il setup crea l'account se manca `config/auth.php`. Il server PHP integrato serve solo allo sviluppo locale.

Il manifest dichiara PHP >= 8.0, ma il codice dell'editor usa il tipo di ritorno `never`, che richiede almeno PHP 8.1. È stato verificato con PHP 8.3. Servono DOM/libxml e le funzionalità standard JSON e sessioni; verifica inoltre la disponibilità delle funzioni di controllo immagini usate da `MediaStore`. Non promettere compatibilità PHP 8.0 sulla sola base di `composer.json`.

Prima di consegnare una conversione:

- Verifica sintassi PHP dei file modificati e sintassi JS con `node --check` dove applicabile. Esegui `php tests/run.php` se tocchi framework, editor o rendering.
- I test pubblici usano `tests/fixtures/`, non i dati reali: il passaggio della suite non prova da solo che il nuovo sito del cliente funzioni. Controlla tutte le nuove pagine e i relativi contenuti reali in un ambiente di sviluppo.
- Verifica desktop e mobile, tastiera, focus, contrasto, menu, link, immagini, titoli/meta description, lingua del documento e 404. Confronta il risultato renderizzato con Figma o HTML di riferimento.
- Prova nell'editor login, salvataggio, upload e riordino per i contenuti introdotti; usa copie isolate per prove distruttive. Non usare i contenuti del cliente come fixture.
- Aggiorna gli snapshot soltanto per cambiamenti intenzionali, con `php tests/run.php --update-snapshots`, e controlla il diff. Non rigenerarli per nascondere regressioni.

Per pubblicare:

- L'hosting deve eseguire PHP e puntare alla root del progetto. Genera `vendor/` con Composer e includilo nell'artefatto se il server non dispone di Composer. Non servono processi Node in produzione per il tema HTML/CSS/JS.
- Al primo rilascio includi template, asset, configurazione delle pagine, schema e contenuti iniziali. Nei rilasci successivi preserva `config/auth.php`, `data/k_data.json`, `data/cms_settings.json`, backup e upload; applica modifiche allo schema con una migrazione coerente e copia di sicurezza.
- PHP deve poter scrivere in `data/`, nella directory dei backup e in `assets/uploads/`; per il setup iniziale deve poter creare `config/auth.php`. Usa permessi appropriati all'hosting, non `777` come soluzione generica.
- Verifica sul server che `config/`, `data/` e `vendor/` non siano scaricabili e che gli upload non eseguano codice. Gli `.htaccess` presenti sono specifici di Apache; Nginx e altri server richiedono regole equivalenti. Verifica la compatibilità delle direttive upload con l'hosting effettivo.
- Escludi dall'artefatto pubblico `.git/`, configurazioni locali degli agenti, test e documenti di sviluppo. Configura HTTPS e verifica login, persistenza e asset sul percorso finale, anche se il sito vive in sottocartella.
- Dopo il rilascio controlla homepage, una pagina di dettaglio, lingua alternativa, 404 e accesso all'editor. Non sovrascrivere le credenziali e non lasciare un setup pubblico non configurato.

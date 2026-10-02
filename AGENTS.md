# Guida operativa per lavorare con Kris

Queste istruzioni valgono per tutta la repository e per gli agenti che la modificano. Il caso d'uso principale è trasformare un design Figma o un sito statico in un sito gestibile con Kris e pubblicabile su hosting PHP. Questa è la fonte unica delle istruzioni; `CLAUDE.md` rimanda qui.

## Regole di lavoro

- Leggi il codice coinvolto prima di modificarlo. Le funzionalità descritte qui sono quelle implementate, non una roadmap.
- Controlla `git status` e preserva le modifiche già presenti, specialmente in `data/` e negli upload. Non ripristinare i dati del cliente con quelli dimostrativi.
- Implementa il sito nei file effettivamente serviti. Non creare cartelle di prototipi, report o piani se non richiesti.
- Per una conversione grafica lavora principalmente su `template/`, `assets/`, schema, dati iniziali e whitelist. Cambia `kris/` (framework ed editor) solo se serve una funzionalità non supportata e il compito lo richiede.
- Conserva nomi dei campi e ID esistenti. Una rinomina è una migrazione dei dati e dei template, non una semplice modifica di etichetta.
- Non introdurre framework frontend o dipendenze di build senza una necessità concreta. Il risultato deve funzionare con il rendering PHP di Kris.
- Alla consegna indica cosa è stato implementato, quali verifiche sono passate e quali integrazioni restano effettivamente mancanti.

## Come funziona il framework

Kris è un CMS PHP basato su file JSON, senza database. Il sito pubblico è renderizzato sul server; l'editor modifica gli stessi dati letti dal sito. Il salvataggio rende immediatamente visibile il contenuto: non esistono stati bozza/pubblicato, autosave o versionamento collaborativo.

| Percorso | Responsabilità |
| --- | --- |
| `index.php`, `sitemap.php`, `editor/index.php`, `editor/upload.php`, `editor/update.php` | Stub fissi di una riga che includono il framework. Non vanno modificati. Gli stub aggiunti da una versione nuova li crea l'aggiornamento (`kris/update/Stubs.php`). |
| `kris/` | Framework ed editor. Viene sostituito in blocco dagli aggiornamenti: niente personalizzazioni del sito qui dentro. |
| `kris/bootstrap.php` | Definisce `KRIS_ROOT` (root del sito) e `KRIS_DIR` (cartella del framework) e registra l'autoload. Il framework accede ai file del sito solo tramite `KRIS_ROOT`, mai con percorsi relativi. |
| `kris/VERSION` | Versione del framework (semver), mostrata nell'editor. Cambia solo con una release. |
| `kris/update/` | Aggiornamento del framework: stato del sito (`SiteState`), canale e pacchetti firmati (`Updater`, `Package`, `Signature`, `Http`), migrazioni (`Migrator`), installazione (`installer.php`) e chiavi pubbliche (`keys.php`). Vedi "Aggiornamenti del framework". |
| `kris/migrations/` | Migrazioni del formato dei dati, `NNNN_nome.php`, applicate in ordine e una sola volta. |
| `kris/maintenance.php` | Pagina 503 mostrata ai visitatori durante un aggiornamento. |
| `kris/public.php` | Entry point pubblico: valida pagina e lingua, carica l'entità, renderizza il template. |
| `kris/404.php`, `404.php` | Pagina 404 del framework; un `404.php` nella root del sito, se presente, la sostituisce. |
| `template/*.html` | Pagine complete e frammenti HTML di liste e componenti. |
| `assets/css/`, `assets/js/`, `assets/images/`, `assets/fonts/` | Stili, interazioni e risorse del tema. Crea le sottocartelle solo quando servono. |
| `assets/uploads/` | File editoriali caricati dal CMS, da preservare nei deploy successivi. |
| `data/k_model.json` | Oggetto che associa ogni raccolta allo schema dei suoi campi. |
| `data/k_data.json` | Lista delle entità con i valori effettivi dei contenuti. |
| `data/cms_settings.json` | Lingue abilitate; opzionale, default `it` e `en`. |
| `data/kris_state.json` | Stato del sito rispetto al framework: formato dei dati, migrazioni applicate, ultimo controllo e registro degli aggiornamenti. Appartiene al sito: non va copiato da un sito all'altro. |
| `data/backups/` | Copie precedenti prodotte dalle scritture tramite `JsonStore`. |
| `data/updates/`, `data/snapshots/`, `data/kris_maintenance.json` | Pacchetti in preparazione, copie di sicurezza degli aggiornamenti (14 giorni) e segnale di manutenzione. Gestiti dall'updater: non modificarli a mano, se non per ripristinare a mano uno snapshot. |
| `config/allowed_pages.json` | Nomi dei template autorizzati come pagine pubbliche. |
| `config/auth.php` | Credenziali locali con password sotto forma di hash; generato dal setup, escluso da Git. |
| `config/seo.json` | Opzionale: indirizzo pubblico del sito, pagine della sitemap, canonical e hreflang automatici. Vedi "SEO: sitemap e indirizzi". |
| `config/update.php` | Opzionale: `return ['enabled' => false];` toglie gli aggiornamenti dall'editor su un sito; `'channel'` cambia il canale. |
| `kris/core/entity/` | `Entity`, repository JSON, scritture atomiche e gestione dei media. |
| `kris/core/template/` | Interpolazione, condizioni, array, componenti e parsing DOM. |
| `kris/core/scripts/script.js` | Utility esistenti per cambio lingua e filtro degli elementi. I template la includono con `kris/core/scripts/script.js`. |
| `kris/core/scripts/consent.js` | Consenso ai cookie, opzionale. Vedi "Privacy e cookie". |
| `kris/core/template/Seo.php`, `kris/sitemap.php` | Canonical, hreflang e sitemap generata dai contenuti a ogni richiesta. |
| `kris/editor/` | Autenticazione, azioni, viste, partial, CSS e JS del pannello. È servito all'indirizzo `/editor/` tramite lo stub. |
| `tests/` | Test PHP e snapshot pubblici con dati isolati in fixture. |

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
8. **Registra le pagine nella sitemap e decidi privacy e cookie.** Ogni tipo di pagina navigabile nuovo va in `config/seo.json` (vedi "SEO: sitemap e indirizzi"); controlla `sitemap.php`. Se il sito usa cookie non tecnici o raccoglie dati, collega banner e informative ai dati di `kris_legal` (vedi "Privacy e cookie"); i testi legali li compila il cliente, non l'agente.
9. **Prepara il deploy.** Applica le verifiche di pubblicazione qui sotto. Pubblica sull'ambiente richiesto solo nell'ambito dell'autorizzazione ricevuta; una richiesta di conversione non identifica da sola un server di destinazione.

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

Gli unici tipi implementati sono `plain`, `text`, `richtext`, `image`, `array`. Non inventare tipi come `boolean`, `select`, `date` o relazioni senza implementarne anche editor e rendering. Usa nomi tecnici stabili in `snake_case` con lettere minuscole, numeri e underscore. Non usare `id` e `language` come nomi di campi: sono esposti dal motore. I nomi di raccolta che iniziano con `kris_` sono riservati al framework.

Un campo `array` di primo livello può avere `"posts": true` (in Struttura: “Mostra in Posts”). Rendering e dati non cambiano: l'elenco compare nella sezione **Posts** dell'editor, con un accesso rapido per ogni entità della raccolta, e i nuovi elementi vengono inseriti in cima. Usalo per blog, news o eventi aggiornati spesso; negli elenchi annidati il flag viene rimosso al salvataggio. Il sito demo lo mostra con `homepage.posts`, `template/post-card.html` e la pagina `post`.

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

## Privacy e cookie

Ogni sito Kris ha la raccolta riservata `kris_legal`, con un solo contenuto (ID `0`) e campi fissi. Il cliente li compila da **Privacy e cookie** nell'editor; non compare tra le raccolte e non si modifica da Struttura. I template la usano solo se vogliono, come qualunque raccolta.

| Campo | Tipo | Contenuto |
| --- | --- | --- |
| `owner_name`, `owner_address`, `owner_vat`, `privacy_email` | `plain` | Titolare del trattamento: nome o ragione sociale, indirizzo, P.IVA o codice fiscale, email per le richieste |
| `updated_at` | `plain` | Data di ultimo aggiornamento delle informative |
| `privacy_policy`, `cookie_policy` | `richtext` | Testi delle informative |
| `banner_title`, `banner_text`, `banner_accept`, `banner_reject`, `banner_more` | `text` | Testi del banner cookie |

Lo schema lo gestisce il framework con le migrazioni (`kris/migrations/0002_privacy_e_cookie.php`): non modificarlo a mano e non aggiungere campi a `kris_legal`. Per un dato legale che manca, proponi una migrazione del framework.

**Pagine delle informative:** un template dedicato che legge l'entità `kris_legal`, per esempio `template/privacy.html` con `{{privacy_policy}}` (i `richtext` escono come HTML) e i dati del titolare. Autorizza la pagina in `config/allowed_pages.json`, linkala dal footer con `index.php?page=privacy&amp;key=kris_legal&amp;ln={{language}}` e aggiungila alla sitemap.

**Banner cookie:** serve solo se il sito usa cookie o script non tecnici (analytics, mappe, video incorporati, pixel). Componente in `template/cookie-banner.html`, incluso con `<div k-component="kris_legal" k-template="cookie-banner" k-index="0"></div>`:

```html
<div class="cookie-banner" data-kris-consent-banner hidden role="dialog" aria-labelledby="cookie-title">
  <h2 id="cookie-title">{{banner_title}}</h2>
  <p>{{banner_text}} <a href="index.php?page=cookie&amp;key=kris_legal&amp;ln={{language}}">{{banner_more}}</a></p>
  <button type="button" data-kris-consent="reject">{{banner_reject}}</button>
  <button type="button" data-kris-consent="accept">{{banner_accept}}</button>
</div>
<script src="kris/core/scripts/consent.js" defer></script>
```

- Gli script non tecnici si scrivono bloccati, `<script type="text/plain" data-kris-consent-script src="…"></script>`: `consent.js` li esegue solo dopo "Accetta". Non caricarli in nessun altro modo.
- "Rifiuta" deve essere visibile e semplice quanto "Accetta"; niente caselle preselezionate. Un link `data-kris-consent="open"` nel footer riapre il banner.
- La scelta resta nel cookie `kris_consent` (`accepted` o `rejected`) per 180 giorni. `window.KrisConsent.status()` la legge; l'evento `kris:consent` su `document` la notifica.
- Lo stile del banner è del tema, in `assets/css/`. Il banner deve restare usabile da tastiera e su mobile.
- Kris non scrive i testi legali e non sa quali cookie usa il sito: segnala al cliente cosa compilare, senza inventare informative.

## SEO: sitemap e indirizzi

Le pagine sono HTML completo generato sul server: i motori di ricerca le leggono come pagine statiche. Kris aggiunge da solo, prima di `</head>`, il `<link rel="canonical">` e i `hreflang` per ogni lingua, a meno che il template non abbia già un canonical. Gli URL canonici omettono i parametri con il valore predefinito (`page` e `key` `homepage`, `id` 0, lingua principale).

`sitemap.php` genera la sitemap dai contenuti a ogni richiesta: un post aggiunto dall'editor compare subito. Quali pagine elencare lo dice `config/seo.json`:

```json
{
  "base_url": "https://www.esempio.it",
  "sitemap": [
    {"page": "homepage"},
    {"page": "progetto", "key": "progetti"},
    {"page": "post", "key": "homepage", "id": 0, "children": "posts"},
    {"page": "privacy", "key": "kris_legal", "id": 0}
  ]
}
```

- Senza `id`: una voce per ogni entità della raccolta. Con `id`: quella sola entità. Con `children`: una voce per ogni elemento della lista indicata (`path=lista/ID`). Si elencano solo pagine autorizzate e contenuti esistenti, in tutte le lingue attive.
- **Regola:** quando aggiungi una pagina navigabile o un tipo di pagina, aggiungila a `config/seo.json` e verifica che compaia in `sitemap.php`. Senza file, la sitemap contiene la sola homepage.
- `base_url` va impostato in produzione: senza, l'indirizzo si ricava dalla richiesta. `"head": false` disattiva canonical e hreflang automatici.
- Al deploy crea `robots.txt` nella root con l'URL assoluto della sitemap, che dipende dal dominio:

```text
User-agent: *
Disallow: /editor/
Sitemap: https://www.esempio.it/sitemap.php
```

Non bloccare `/kris/` né `/assets/`: contengono gli script e gli stili delle pagine. Segnala al cliente di inviare la sitemap in Google Search Console.

## Editor e persistenza

`kris/editor/index.php` gestisce sessione, autenticazione e dispatch; `actions.php` le mutazioni; `helpers.php` schema e percorsi; `views/` le pagine; `partials/` gli elementi condivisi. `scripts.js` gestisce i flussi UI e `structure.js` la modifica dello schema.

Mantieni autenticazione e CSRF per ogni mutazione. I salvataggi asincroni confermano il risultato del server prima di mostrare successo; gli errori devono conservare i campi compilati. Il riordino salva senza ricarica e senza spostare lo scroll, anche con testi non ancora salvati. Non annidare form HTML: i controlli delle liste usano form separati associati tramite l'attributo `form`.

L'editor permette di configurare anche gli schemi `of` e presenta l'impatto delle modifiche distruttive. I nomi dei campi devono continuare a corrispondere ai template. Non introdurre messaggi che promettono bozze, undo o gestione conflitti non implementati.

## Aggiornamenti del framework

Da un sito installato l'admin aggiorna Kris da Impostazioni › Versione di Kris: verifica, scaricamento o caricamento dello zip, installazione. Il flusso è in `kris/update/` e il piano con le motivazioni in `PIANO_update_claude.md`.

- **Canale:** `releases.json` e `releases.json.sig` su `main`, letti da raw.githubusercontent.com. Mai l'API di GitHub. Solo `https` e host in `Http::ALLOWED_HOSTS`.
- **Firme:** Ed25519. Le chiavi pubbliche stanno in `kris/update/keys.php`; le segrete fuori dalla repository. Un pacchetto è uno zip della sola `kris/` con `RELEASE.json`, `MANIFEST.json` (sha256 di ogni file) e `MANIFEST.sig`: si verifica da solo, anche caricato a mano.
- **Policy:** dall'editor si installano solo versioni con la stessa major e non `breaking`. Le major le installa lo sviluppatore.
- **Installazione:** preflight reale (scrittura e spostamento di cartelle), controllo di sintassi, snapshot di `data/*.json` e `config/`, manutenzione, scambio di `kris/`, migrazioni, verifica delle pagine con i contenuti veri. A ogni errore, anche fatale, il sito torna com'era da solo.
- **Contratto con le versioni installate:** `installer.php` espone `kris_update_install(array $ctx): array` e arriva dentro il pacchetto nuovo; i campi di `releases.json` e `RELEASE.json`, i percorsi in `data/` e il formato di `kris_maintenance.json` si possono estendere, mai rinominare. Un sito vecchio legge il canale con il suo codice.
- **`bootstrap.php`** viene incluso di nuovo dall'installer dopo lo scambio: niente dichiarazioni che non tollerino la ripetizione.

**Cambiare il formato dei dati richiede una migrazione** in `kris/migrations/`: numerata, solo in avanti, funzione pura su `['k_data', 'k_model', 'cms_settings']`, senza usare classi del core, idempotente, con un test fixture prima → fixture dopo. `'auto' => true` solo se non cambia i contenuti. Non è una migrazione la rinomina di un campo di un sito cliente: quella è contenuto del sito.

**Pubblicare una release** (solo lo sviluppatore, con la chiave privata):

```sh
# aggiorna kris/VERSION con una PR, uniscila, poi da main pulito e allineato:
php -d extension=sodium -d extension=zip tools/release.php --key <percorso>/principale.key \
    --changelog "Testo per i clienti" --publish          # --dry-run per provare senza pubblicare
```

Lo script controlla repository e test, costruisce e verifica il pacchetto, chiede conferma, poi crea tag e release GitHub con lo zip, riscarica lo zip per confrontarlo e solo alla fine pubblica `releases.json` e `.sig` su `main`. Se si interrompe, si rilancia lo stesso comando: i passi già fatti vengono riconosciuti, e uno zip già pubblicato viene riusato invece di essere ricostruito. Servono `git` e `gh` autenticato. Il changelog lo leggono i clienti nell'editor. `--breaking` per le release che richiedono lo sviluppatore, `--min-from` se serve un passaggio intermedio.

## Avvio, verifica e deploy

Esegui i comandi dalla radice del progetto:

```sh
php -S 127.0.0.1:8000 -t .
# In un altro terminale:
php tests/run.php
```

Le suite dei pacchetti e dell'installazione richiedono le estensioni `sodium` e `zip`: se mancano vengono saltate e il riepilogo lo dice (`SALTATA`). Con un PHP che non le carica dal php.ini: `php -d extension=sodium -d extension=zip tests/run.php`. Una suite saltata non è una suite passata.

Apri `http://127.0.0.1:8000/` e `/editor/`. Il setup crea l'account se manca `config/auth.php`. Il server PHP integrato serve solo allo sviluppo locale.

Serve almeno PHP 8.0, che molti hosting condivisi hanno ancora: niente sintassi o funzioni introdotte dopo (per esempio il tipo `never`, `enum`, `readonly`; `array_is_list` ha un rimpiazzo in `kris/bootstrap.php`). La CI lo verifica su 8.0, 8.1 e 8.3. Servono DOM/libxml, mbstring e le funzionalità standard JSON e sessioni; verifica inoltre la disponibilità delle funzioni di controllo immagini usate da `MediaStore`. Per aggiornare dall'editor servono anche `sodium` e `zip`, e cURL (o `allow_url_fopen`) con i certificati configurati: senza, resta il caricamento manuale del pacchetto oppure l'aggiornamento via FTP.

Prima di consegnare una conversione:

- Verifica sintassi PHP dei file modificati e sintassi JS con `node --check` dove applicabile. Esegui `php tests/run.php` se tocchi framework, editor o rendering.
- I test pubblici usano `tests/fixtures/`, non i dati reali: il passaggio della suite non prova da solo che il nuovo sito del cliente funzioni. Controlla tutte le nuove pagine e i relativi contenuti reali in un ambiente di sviluppo.
- Verifica desktop e mobile, tastiera, focus, contrasto, menu, link, immagini, titoli/meta description, lingua del documento e 404. Confronta il risultato renderizzato con Figma o HTML di riferimento.
- Prova nell'editor login, salvataggio, upload e riordino per i contenuti introdotti; usa copie isolate per prove distruttive. Non usare i contenuti del cliente come fixture.
- Aggiorna gli snapshot soltanto per cambiamenti intenzionali, con `php tests/run.php --update-snapshots`, e controlla il diff. Non rigenerarli per nascondere regressioni.

Per pubblicare:

- L'hosting deve eseguire PHP e puntare alla root del progetto. Non serve Composer: l'autoload è in `kris/bootstrap.php`. Non servono processi Node in produzione per il tema HTML/CSS/JS.
- Al primo rilascio includi template, asset, configurazione delle pagine, schema e contenuti iniziali. Nei rilasci successivi preserva `config/`, tutto `data/` (compresi `kris_state.json`, backup e snapshot) e gli upload; applica modifiche allo schema con una migrazione coerente e copia di sicurezza. Aggiornando il framework via FTP sostituisci la cartella `kris/` intera, non file per file: l'editor registra il cambio di versione e applica o propone le migrazioni.
- PHP deve poter scrivere in `data/`, nella directory dei backup e in `assets/uploads/`; per il setup iniziale deve poter creare `config/auth.php`; per aggiornare dall'editor deve poter spostare `kris/` nella root del sito. Usa permessi appropriati all'hosting, non `777` come soluzione generica.
- Verifica sul server che `config/` e `data/` non siano scaricabili e che gli upload non eseguano codice. Gli `.htaccess` presenti sono specifici di Apache; Nginx e altri server richiedono regole equivalenti. Verifica la compatibilità delle direttive upload con l'hosting effettivo.
- Escludi dall'artefatto pubblico `.git/`, configurazioni locali degli agenti, test e documenti di sviluppo. Configura HTTPS e verifica login, persistenza e asset sul percorso finale, anche se il sito vive in sottocartella.
- Dopo il rilascio controlla homepage, una pagina di dettaglio, lingua alternativa, 404, `sitemap.php`, `robots.txt` e accesso all'editor. Non sovrascrivere le credenziali e non lasciare un setup pubblico non configurato.

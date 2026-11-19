# Guida operativa per lavorare con Kris

Queste istruzioni valgono per tutta la repository e per gli agenti che la modificano. Il caso d'uso principale è trasformare un design Figma o un sito statico in un sito gestibile con Kris e pubblicabile su hosting PHP. Questa è la fonte unica delle istruzioni; `CLAUDE.md` rimanda qui.

## Regole di lavoro

- Leggi il codice coinvolto prima di modificarlo. Le funzionalità descritte qui sono quelle implementate, non una roadmap.
- Controlla `git status` e preserva le modifiche già presenti, specialmente in `data/` e negli upload. Non ripristinare i dati del cliente con quelli dimostrativi.
- Implementa il sito nei file effettivamente serviti. Non creare cartelle di prototipi, report o piani se non richiesti.
- Per una conversione grafica lavora principalmente su `template/`, `assets/`, schema, dati iniziali e whitelist. Cambia `kris/` (framework ed editor) solo se serve una funzionalità non supportata e il compito lo richiede.
- Conserva nomi dei campi e ID esistenti. Una rinomina è una migrazione dei dati e dei template, non una semplice modifica di etichetta.
- Non introdurre framework frontend o dipendenze di build nei temi: il sito pubblico è HTML, CSS e JS reso da Kris in PHP. L'editor è un'app Vue con i sorgenti in `admin-src/` (vedi "Editor e persistenza"): solo chi la cambia ha bisogno di Node.
- Alla consegna indica cosa è stato implementato, quali verifiche sono passate e quali integrazioni restano effettivamente mancanti.

## Come funziona il framework

Kris è un CMS PHP basato su file JSON, senza database. Il sito pubblico è renderizzato sul server; l'editor modifica gli stessi dati letti dal sito. Il salvataggio rende immediatamente visibile il contenuto: non esistono stati bozza/pubblicato, autosave o versionamento collaborativo.

| Percorso | Responsabilità |
| --- | --- |
| `index.php`, `sitemap.php`, `kris-agent.php`, `kris-admin/index.php`, `kris-admin/upload.php`, `kris-admin/update.php` | Stub fissi di una riga che includono il framework. Non vanno modificati. Gli stub aggiunti da una versione nuova li crea l'aggiornamento (`kris/update/Stubs.php`). |
| `kris/` | Framework ed editor. Viene sostituito in blocco dagli aggiornamenti: niente personalizzazioni del sito qui dentro. |
| `kris/bootstrap.php` | Definisce `KRIS_ROOT` (root del sito) e `KRIS_DIR` (cartella del framework) e registra l'autoload. Il framework accede ai file del sito solo tramite `KRIS_ROOT`, mai con percorsi relativi. |
| `kris/VERSION` | Versione del framework (semver), mostrata nell'editor. Cambia solo con una release. |
| `kris/update/` | Aggiornamento del framework: stato del sito (`SiteState`), canale e pacchetti firmati (`Updater`, `Package`, `Signature`, `Http`), migrazioni (`Migrator`), installazione (`installer.php`) e chiavi pubbliche (`keys.php`). Vedi "Aggiornamenti del framework". |
| `kris/migrations/` | Migrazioni del formato dei dati, `NNNN_nome.php`, applicate in ordine e una sola volta. |
| `kris/maintenance.php` | Pagina 503 mostrata ai visitatori durante un aggiornamento. |
| `kris/public.php` | Entry point pubblico: manutenzione e anteprima; il rendering della pagina (pagina e lingua validate, entità, template, canonical, favicon) è in `Kris\Site\PageRenderer`, usato anche dall'agente. |
| `template/404.html`, `404.php`, `kris/404.php` | Pagina 404, in quest'ordine: il template del tema (vedi "Pagina 404"), un `404.php` nella root del sito (siti precedenti alla 1.4.3), quella generica del framework. |
| `template/*.html` | Pagine complete e frammenti HTML di liste e componenti. |
| `assets/css/`, `assets/js/`, `assets/images/`, `assets/fonts/` | Stili, interazioni e risorse del tema. Crea le sottocartelle solo quando servono. |
| `assets/uploads/` | File editoriali caricati dal CMS, da preservare nei deploy successivi. |
| `data/schema.json` | Schema del sito (formato 2): tipi con i loro campi e attributi, pagine pubbliche con il tipo che rendono e la sitemap, elenchi promossi, reindirizzamenti. Vedi "Il formato dei dati". |
| `data/content.json` | Lista dei contenuti, nell'ordine, con i valori per nome. |
| `data/settings.json` | Lingue attive (predefinite `it` e `en`), caricamenti, opzioni SEO del sito (`seo`). |
| `data/format1/` | Copia dei file della 1.x (`k_data.json`, `k_model.json`, `cms_settings.json`, `allowed_pages.json`, `seo.json`) dopo la conversione della migrazione 0009. Non li legge più nessuno: non vanno modificati. |
| `data/kris_state.json` | Stato del sito rispetto al framework: formato dei dati, migrazioni applicate, ultimo controllo e registro degli aggiornamenti. Appartiene al sito: non va copiato da un sito all'altro. |
| `data/backups/` | Copie precedenti prodotte dalle scritture tramite `JsonStore`. |
| `data/staging/`, `data/staging-backups/` | Modifiche al tema in attesa di pubblicazione e copie dei file sostituiti dalle ultime pubblicazioni (14 giorni). Gestite da `Kris\Staging\Staging`: vedi "Staging e anteprima". |
| `data/updates/`, `data/snapshots/`, `data/kris_maintenance.json` | Pacchetti in preparazione, copie di sicurezza degli aggiornamenti (14 giorni) e segnale di manutenzione. Gestiti dall'updater: non modificarli a mano, se non per ripristinare a mano uno snapshot. |
| `config/users.php` | Utenti dell'editor: nome, ruolo (`admin` o `base`), hash della password, email di recupero, bloccato sì/no, numero di sessione; e l'indirizzo dell'editor da cui partono i link di reset. Creato dal setup, escluso da Git. Un `config/auth.php` della 1.x diventa il primo admin alla prima lettura e si toglie (`Kris\Auth\Users`). |
| `config/setup_code.php` | Codice di primo avvio, creato da Kris finché non ci sono utenti ed eliminato quando l'amministratore è creato. Escluso da Git. |
| `config/auth_reset.json` | Hash dei link di reset in corso, uno per utente, e richieste dell'ultima ora. Escluso da Git. |
| `config/auth_attempts.json` | Accessi sbagliati degli ultimi 15 minuti (IP non in chiaro): dopo 5 dallo stesso indirizzo, o 50 in tutto, l'accesso aspetta (`Kris\Auth\LoginThrottle`). Escluso da Git. |
| `config/agent.php` | Abbinamento con Kris Agent: indirizzo dell'API, id del sito, chiave pubblica del Worker, chiavi del sito. Creato dall'editor, escluso da Git, privato come `auth.php`. |
| `config/update.php` | Opzionale: `return ['enabled' => false];` toglie gli aggiornamenti dall'editor su un sito; `'channel'` cambia il canale. |
| `kris/core/auth/` | Credenziali (`Credentials`) e link di reset della password (`PasswordReset`). |
| `kris/core/entity/` | `Entity`, repository JSON, scritture atomiche e gestione dei media. |
| `kris/core/store/` | Archivio dei dati (`Kris\Store\Store`): documenti `schema`, `content`, `settings` con revisione (e quelli della 1.x, che legge solo la migrazione 0009), transazioni sotto lock, esportazione e importazione. `Kris\Store\Site::load()` dà a rendering, editor e staging i tre documenti, convertiti al volo se il sito è ancora nel formato 1. `Stores::site()` sceglie l'adapter da `config/store.php` (privato, escluso da Git): `json` predefinito (`FileStore` sui file di sempre) oppure `pdo` (`PdoStore`, SQLite o MySQL/MariaDB, un documento per riga nella tabella `<prefisso>documents`, creata al primo uso; un percorso SQLite relativo vale dalla root, per esempio `sqlite:data/kris.sqlite`). Il passaggio si fa con `php tools/store.php convert --to=pdo --dsn=…` (o `--to=json`): copia, rilegge e confronta, solo allora scrive `config/store.php`; l'archivio vecchio resta come copia. Con il database le copie di sicurezza degli aggiornamenti conservano un'esportazione (`store.json` nello snapshot). Vedi `docs/2.0/specifica.md`. |
| `kris/core/staging/` | Staging (`Staging`), operazioni su contenuti, schema e pagine (`Operations`), file ammessi e controlli sul contenuto (`FilePolicy`), anteprima per gli admin (`Preview`), differenze per il riepilogo (`Diff`), traduzione delle azioni dell'editor in operazioni (`EditorChanges`). |
| `tools/staging.php` | Le operazioni sullo staging da riga di comando: `status`, `read`, `edit`, `write`, `delete`, `approve`, `op`, `upload`, `promote`, `discard`, `undo`. |
| `kris/core/site/Paths.php` | Unico punto da cui il rendering pubblico legge template, `data/` e pagine autorizzate. Con una sovrapposizione attiva (l'anteprima dello staging) un file presente lì vince su quello del sito. Solo letture: le scritture vanno sempre ai file del sito. |
| `kris/core/template/` | Interpolazione, condizioni, array, componenti e parsing DOM. |
| `kris/core/scripts/script.js` | Utility esistenti per cambio lingua e filtro degli elementi. I template la includono con `kris/core/scripts/script.js`. |
| `kris/core/scripts/consent.js` | Consenso ai cookie, opzionale. Vedi "Privacy e cookie". |
| `kris/core/template/Seo.php`, `kris/sitemap.php` | Canonical, hreflang e sitemap generata dai contenuti a ogni richiesta. |
| `kris/editor/` | L'editor: `index.php` (accesso, stato del framework, modalità, carica l'app), `api.php` (API JSON dell'app), `model.php` (il formato 2 per l'editor), `auth.php`, `upload.php`, `update.php`, e `dist/` con l'app Vue compilata e versionata. È servito all'indirizzo `/kris-admin/` tramite lo stub (fino alla 1.x `/editor/`: l'aggiornamento alla 2.0 crea `kris-admin/` e trasforma gli stub di `editor/` in un rimando; i link di reset salvati con il vecchio indirizzo si correggono da soli). |
| `admin-src/` | Sorgenti dell'app Vue dell'editor (Vite, Pinia, Vue Router; struttura del boilerplate `fellarrusto/vue-fe-boilerplate`, senza PrimeVue). `npm run build` scrive in `kris/editor/dist/`. Non va sul sito. |
| `tests/` | Test PHP e snapshot pubblici con dati isolati in fixture. |

`Entity` legge i valori e risolve le traduzioni. `JsonRepository` recupera le entità per nome e ID. `JsonStore` verifica il JSON, scrive tramite file temporaneo e conserva copie precedenti; non garantisce il rilevamento di conflitti tra editor concorrenti. Per letture e scritture dei dati del sito usa l'archivio (`Kris\Store\Stores::site()`), mai i file di `data/` direttamente: dalla 2.0 dietro può esserci un database. Anche le migrazioni passano dall'archivio; l'installer, prima dello scambio, usa solo funzioni del suo file.

Il rendering esegue prima variabili e condizioni, poi gli array, poi i componenti. Ogni frammento viene renderizzato con la propria entità. Il frontend legge i tipi presenti nei dati: modificare soltanto lo schema non converte automaticamente i dati già salvati.

## Da Figma o HTML statico a un sito Kris

1. **Leggi il materiale di partenza.** Identifica pagine, componenti condivisi, breakpoint, font, colori, spaziature, immagini e stati delle interazioni. Se il file Figma non è accessibile, richiedi il link accessibile o gli export necessari; non dichiarare di averlo consultato. Non installare strumenti o dipendenze solo perché il progetto nasce da Figma.
2. **Mappa contenuti e navigazione.** Per ogni pagina identifica template, raccolta, ID e URL. Elenca i campi che il cliente deve poter modificare e le liste che deve poter aggiungere, eliminare e riordinare.
3. **Costruisci il tema reale.** Traduci il design in HTML semantico, CSS responsive e JS per le interazioni. Mantieni identità grafica, tipografia e gerarchia del riferimento; non imporre al sito pubblico il design dell'editor. Usa token CSS per i valori ricorrenti e componenti per gli elementi condivisi.
4. **Definisci schema e contenuti insieme.** Ogni campo modificabile deve avere una definizione nel tipo in `data/schema.json`, un valore compatibile in `data/content.json` e un utilizzo nel template. Usa descrizioni comprensibili nell'editor. Inizializza le lingue previste con contenuti reali o chiaramente provvisori.
5. **Collega il markup a Kris.** Sostituisci i contenuti editoriali con `{{campo}}`, estrai le liste ripetute in frammenti `k-array` e le sezioni condivise in `k-component`. Rimuovi le card statiche di esempio dai contenitori delle liste.
6. **Completa i flussi.** Menu mobile, modali, accordion, filtri, CTA e link devono funzionare anche con tastiera e contenuti di lunghezza diversa. Gestisci liste vuote, immagini mancanti e testi lunghi. I moduli contatto richiedono un backend esplicito: Kris non offre già un servizio email, prenotazioni, pagamenti o ricerca server.
7. **Verifica sito ed editor insieme.** Modifica un testo, un'immagine e l'ordine di una lista nell'editor e verifica l'effetto sul sito pubblico. Confronta le pagine renderizzate con il riferimento desktop e mobile.
8. **Registra le pagine nella sitemap e decidi privacy e cookie.** Ogni pagina navigabile nuova va in `schema.pages`, con il tipo che rende e `"sitemap": true` se va nella sitemap (vedi "SEO: sitemap e indirizzi"); controlla `sitemap.php`. Se il sito usa cookie non tecnici o raccoglie dati, collega banner e informative ai dati di `kris_legal` (vedi "Privacy e cookie"); i testi legali li compila il cliente, non l'agente.
9. **Prepara il deploy.** Applica le verifiche di pubblicazione qui sotto. Pubblica sull'ambiente richiesto solo nell'ambito dell'autorizzazione ricevuta; una richiesta di conversione non identifica da sola un server di destinazione.

### Cosa rendere modificabile

| Elemento del design | Modellazione consigliata |
| --- | --- |
| Titoli, descrizioni, testo dei pulsanti, alt delle immagini | `text` con `"localized": true`. |
| Testo con paragrafi, elenchi e link editoriali | `richtext`, soltanto dove è previsto HTML: esce filtrato (vedi "Contratto dei template"). |
| Immagini, video e documenti sostituibili | `media` (per lingua con `"localized": true`, come la 1.x); alt e didascalia sono campi separati. |
| Valore condiviso tra lingue, URL comune, codice, prezzo | `text` con `"localized": false`. |
| Card, servizi, FAQ o testimonianze appartenenti a una pagina | `list` con i campi in `of`. |
| Articoli, news o progetti con pagina di dettaglio | Un tipo con i suoi contenuti e una pagina senza `item`; `"newest_first": true` per gli articoli (Posts). |
| Navigazione, footer e contenuti riutilizzati su più pagine | Raccolta dedicata con entità condivisa e `k-component`. |
| Griglie, spaziature, breakpoint, decorazioni e animazioni | HTML/CSS/JS del tema; non campi editoriali generici. |

I tipi di campo sono `text`, `richtext`, `media`, `select`, `boolean` e `list`. Tutti tranne `list` hanno `localized`: `true` vuol dire un valore per lingua (`{"it": "…", "en": "…"}`), `false` un valore solo. Un interruttore (`boolean`) vale `true` o `false` (nei template `"1"` o `""`, e nelle condizioni vero o falso); un interruttore per lingua spento non ricade su un'altra lingua. `select` ha `options` (lista di stringhe senza virgole) e, con `"multiple": true`, salva una lista di opzioni (`["web", "seo"]`), che nei template esce separata da virgole e si controlla con `has`. Le operazioni dello staging accettano anche i nomi della 1.x: `plain` (testo condiviso), `image` (media), `array` (list). Non inventare tipi come `date` o relazioni senza implementarne anche editor e rendering. Usa nomi tecnici stabili in `snake_case` con lettere minuscole, numeri e underscore. Non usare `id` e `language` come nomi di campi: sono esposti dal motore. I nomi di tipo che iniziano con `kris_` sono riservati al framework.

Un tipo può stare in **Posts** (Struttura della raccolta › “Mostra in Posts”, `"newest_first": true` nello schema): ogni suo contenuto è un post e i nuovi vanno in cima. È il modo di fare blog, news o eventi aggiornati spesso; un elenco (`list`) non va in Posts. Il sito demo lo mostra con il tipo `posts`, `template/post-card.html` e la pagina `post`.


Ogni tipo può avere, separati dal nome tecnico, un **nome visibile** (`label`), una **descrizione** (`description`), una **posizione** (l'ordine dei tipi nello schema) e l'opzione **contenuto unico** (`single`), da Struttura › Nome e posizione. Un tipo a contenuto unico (una pagina, gli elementi comuni) apre direttamente la modifica, porta il nome del tipo come titolo e non ha lista, «Nuovo contenuto» né cestino: il server rifiuta un secondo contenuto e l'eliminazione. Il nome tecnico resta quello dei template: per cambiare l'etichetta non si rinomina il tipo. Lo staging li imposta con `set_collection_info` (`label`, `description`, `single`) e `move_collection` (`to`).

Ogni campo, a qualunque livello, può avere `"section": "Meta"` (al massimo 60 caratteri). In Struttura le sezioni dei campi di primo livello sono riquadri: «Aggiungi sezione», nome scritto una volta, campi trascinati dentro o spostati con il menu «Sezione»; le sezioni vuote non si salvano. Nell'editor i campi con la stessa sezione stanno in un pannello a scomparsa, nell'ordine in cui le sezioni compaiono; i campi senza sezione restano in testa. Serve solo a ordinare l'editor: dati, template e nomi dei campi non cambiano. Su una pagina con molti campi raggruppa per area (Meta, Intestazione, Contatti, Progetti…). Lo staging la imposta con `add_field` o `describe_field` (`section`, vuota per toglierla).

## Il formato dei dati

Tre documenti (formato 2, dalla 2.0; la migrazione `0009_formato_2.php` converte i siti della 1.x). In un sito nuovo questo è un esempio minimo di `data/schema.json`; su un sito esistente integra tipi e pagine senza sostituire l'intero schema.

```json
{
  "format": 2,
  "types": {
    "homepage": {
      "single": true,
      "fields": [
        {"name": "page_title", "type": "text", "localized": true, "description": "Titolo principale"},
        {"name": "services", "type": "list", "description": "Servizi in evidenza", "of": [
          {"name": "title", "type": "text", "localized": true, "description": "Nome del servizio"},
          {"name": "description", "type": "text", "localized": true, "description": "Descrizione breve"}
        ]}
      ]
    }
  },
  "pages": {
    "homepage": {"template": "homepage", "type": "homepage", "item": 0, "sitemap": true}
  }
}
```

`data/content.json` è la lista dei contenuti, con i valori per nome. Il tipo di un campo sta solo nello schema:

```json
[
  {"type": "homepage", "id": 0, "values": {
    "page_title": {"it": "Progettiamo il tuo futuro", "en": "Designing your future"},
    "services": [
      {"id": 0, "values": {"title": {"it": "Design", "en": "Design"}, "description": {"it": "Siti su misura", "en": "Tailored websites"}}}
    ]
  }}
]
```

`data/settings.json` ha le lingue (la prima è la principale), le opzioni dei caricamenti e quelle SEO del sito:

```json
{"languages": ["it", "en"], "uploads": {"prefix": "", "max_width": 2560, "webp": true}, "seo": {"base_url": "https://www.esempio.it", "pretty_urls": true}}
```

Le pagine dello schema: la chiave è il nome della pagina (e dell'indirizzo), `template` il file in `template/`, `type` il tipo che rende. Con `item` rende sempre quel contenuto (homepage, privacy); senza `item` un contenuto del tipo per id (un progetto, un post); con `item` e `list` un elemento di quell'elenco (`path=list/ID`). `sitemap` (vero o falso) la mette nella sitemap, `priority` (0–1) va nella sitemap, `slug` è il campo che dà l'indirizzo leggibile. Lo schema può avere anche `redirects` (`{"/vecchio": "/nuovo"}`) e `promoted`: gli elenchi della 1.x con una pagina di dettaglio diventati tipi alla conversione (`{"from": "homepage", "id": 0, "list": "posts", "to": "posts"}`), per i vecchi link `key=homepage&id=0&path=posts/3`, che continuano a funzionare.

Un valore il cui campo non c'è più nello schema resta nel contenuto e non si rende; lo toglie solo `remove_field`.
Ogni contenuto e ogni elemento di un elenco può avere `"hidden": true` accanto a `id` e `values`: l'editor lo imposta con l'interruttore **Visibile / Sospeso**. Il sito non lo mostra nelle liste `k-array`, la sua pagina di dettaglio risponde 404 e non compare nella sitemap; nei dati e nell'editor resta. Senza la chiave l'elemento è visibile. I template non devono fare nulla: non usare `hidden` per altri scopi, non impostarlo nei dati iniziali se non è voluto, e non scrivere link fissi a singoli elementi che il cliente potrebbe sospendere. I componenti `k-component` (navbar, footer, `kris_legal`) non si sospendono.

Gli id dei contenuti sono univoci nel tipo; quelli degli elementi nel singolo elenco. L'ordine è la sequenza nel JSON, non il valore numerico degli id. Riordinare non deve rinumerarli.

## Esempio completo: pagina con servizi

Con lo schema e i contenuti dell'esempio qui sopra:

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

La griglia resta nel template della pagina; il frammento contiene una sola card. Gli stili sono in `assets/css/style.css`. La pagina `homepage` è in `schema.pages`; il frammento `service-card` no. L'URL è `index.php?page=homepage&key=homepage&id=0&ln=it`.

## Contratto dei template

- `{{campo}}` legge dall'entità corrente. `{{id}}` è l'ID corrente e `{{language}}` la lingua attiva. `{{raccolta.campo}}` legge il primo contenuto di un'altra raccolta da qualunque template (impostazioni, footer, contatti), anche nelle condizioni (`{{#if impostazioni.dark_mode}}`). Non sono supportati filtri tipo Twig, accessi al contenuto padre (`parent.title`), indici di elenchi, espressioni JavaScript o loop `for`.
- I valori `text`, `media`, `select` e `boolean` vengono escapati per HTML, comprese le virgolette negli attributi. `richtext` emette HTML filtrato (`Kris\Template\Richtext`, al salvataggio e al rendering): restano `p br strong b em i u s a ul ol li h2–h6 blockquote code pre span img figure figcaption table thead tbody tr th td hr`, gli attributi `class`, `title`, `href` (http, https, mailto, tel, relativi, `#`), `target`, `rel`, `src` e `alt` delle immagini (del sito o https), `colspan` e `rowspan`; il resto si toglie (un `<div>` lascia il suo contenuto, uno `<script>` sparisce). Un embed o altro HTML libero va nel template, non in un campo. L'escaping HTML non valida gli schemi degli URL dei campi `text`: controlla gli URL editoriali e non interpolare valori dentro codice JavaScript.
- Per una traduzione vuota o assente, `Entity` cerca il primo valore non vuoto disponibile. La stringa `"0"` è valida. Le lingue si configurano in `data/settings.json` tramite l'editor; disabilitarne una conserva i valori già salvati.
- `k-array="services"` cerca prima un elenco del contenuto corrente. Se il tipo non ha quell'elenco, rende i contenuti del tipo omonimo (così `k-array="posts"` elenca i post); un elenco vuoto resta vuoto. Il contenitore resta nel DOM e i frammenti vengono aggiunti al suo contenuto: deve essere inizialmente vuoto.
- Inserisci gli array annidati nel frammento del padre, ciascuno con il proprio `k-template`. Non scrivere il markup dei figli direttamente nel contenitore della pagina: le variabili verrebbero risolte nel contesto sbagliato.
- Un componente, ad esempio `<div k-component="navbar" k-template="navbar" k-index="0"></div>`, carica il contenuto `navbar` con id `0`. Il segnaposto viene **sostituito** dal frammento: metti tag semantico, classi e attributi sul markup di `template/navbar.html`, non sul segnaposto. Crea anche il tipo e il contenuto condiviso.
- Un frammento può contenere più elementi fratelli. Non introdurre inclusioni ricorsive o cicliche tra componenti e liste.
- Le condizioni supportano `#if`, `#elif`, `#else`, `/if`, la forma semplice `{{#if campo}}` (vera con un valore non vuoto: un interruttore acceso) e gli operatori `==`, `!=`, `>`, `<`, `>=`, `<=`, `has`. `has` controlla un valore in una stringa di tag separati da virgole. Per campi opzionali inizializza il valore e verifica il risultato con campo vuoto e assente.

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

Una pagina pubblica richiede tre cose: template esistente, pagina in `schema.pages` e contenuto esistente. `page` sceglie la pagina; `key` il tipo; `id` il contenuto. I default sono `homepage`, `homepage`, `0`.

- Root: `index.php?page=project-detail&key=projects&id=3&ln=it`.
- Figlio: `index.php?page=detail&key=homepage&id=0&path=services/0&ln=it`.
- Più livelli: `path=services/0/highlights/2`. Il percorso alterna nome array e ID figlio.

Mantieni la lingua nei link con `ln={{language}}` e usa `&amp;` tra parametri negli attributi HTML. Nel frammento di un figlio `{{id}}` indica il figlio, non il padre: non usarlo come ID root. In un contesto riutilizzabile modella un URL esplicito o fornisci il contesto necessario; non presumere variabili parent inesistenti.

**Indirizzi leggibili** (`"pretty_urls": true` in `settings.seo`, `Kris\Site\Router`): `/` per la homepage, `/menu`, `/progetto/3`, `/en/menu` per le altre lingue. Le rotte vengono dalle pagine dello schema: con `item` una pagina fissa (`/privacy`), senza `item` un contenuto del tipo per id (`/progetto/3`), con `list` un elemento dell'elenco (`/detail/2`). Kris riscrive da solo i link `index.php?page=…` e `?page=…` dei template nei percorsi leggibili, e fa partire `assets/`, `kris/` e `index.php` dalla cartella del sito (una pagina `/en/menu` li cercherebbe in `/en/`); canonical, hreflang e sitemap usano i percorsi. I vecchi indirizzi rispondono con un 301; `?ln=` su un percorso porta alla stessa pagina nell'altra lingua. Una pagina senza `item` può avere `"slug": "<campo>"`: l'indirizzo del contenuto è il valore di quel campo come segmento (`/progetto/casa-rossi`), per lingua se il campo è multilingua; vuoto vale l'id, che resta sempre valido, e un doppione riceve `-id`. Non c'è storico: rinominando lo slug il vecchio indirizzo va in `schema.redirects` (`{"/vecchio": "/nuovo"}`, percorsi dalla cartella del sito o indirizzi https), che rispondono con un 301 anche senza `pretty_urls`. Quello che una rotta non sa esprimere resta `index.php?…`. Serve la regola del `.htaccess` di Kris (`index.php [L,QSA]`): l'aggiornamento la crea, o aggiorna quella della 1.x. Senza `pretty_urls` valgono le route query string. La whitelist riguarda le pagine navigabili, non tutti i frammenti HTML.

**Head comune:** `template/kris_head.html`, se esiste, entra prima di `</head>` in ogni pagina e nella 404 del tema, reso con il contenuto della pagina: meta di verifica, script globali (bloccati con `data-kris-consent-script` se non tecnici), dati strutturati. Una modifica qui vale per tutte le pagine; non ripetere queste righe nei template.

**Lingua automatica** (`"auto_language": true` in `settings.seo`): la homepage senza lingua nell'indirizzo va (302) alla lingua scelta prima dal visitatore (cookie `kris_lang`, 180 giorni, impostato da `?ln=` o dal prefisso della lingua) o, la prima volta, alla prima lingua attiva di `Accept-Language`. I crawler vedono sempre la lingua principale. Le altre pagine non vengono reindirizzate.

Kris aggiunge da solo `?v=<data di modifica>` agli indirizzi `assets/css/…` e `assets/js/…` delle pagine (`Kris\Template\Assets`): dopo una pubblicazione browser e proxy scaricano subito i file nuovi. Non scrivere a mano versioni o query su questi file, salvo motivi precisi: un indirizzo che ha già una query resta com'è.

I percorsi delle risorse si risolvono rispetto all'URL pubblico, non alla cartella `template/`. Usa `assets/...` per l'installazione corrente e verifica anche il deploy in sottocartella; `/assets/...` punta invece alla radice del dominio. Non lasciare link a file locali, export Figma o URL temporanei di preview.

Esporta immagini e icone necessarie dal design, preserva le proporzioni, ottimizza peso e dimensioni e usa font disponibili per il progetto. Gli SVG fidati del tema possono essere asset statici verificati. L'upload editoriale accetta JPG/JPEG, PNG, GIF, WebP, AVIF e PDF fino a 8 MB, e video MP4 e WebM fino a 50 MB (o meno, se `upload_max_filesize` e `post_max_size` dell'hosting sono più bassi); non accetta nuovi SVG né `.mov`, che non si vedono in tutti i browser e Kris non converte. Un video si mette in un campo `media` come gli altri file: nell'editor l'anteprima è un `<video>`, nel template lo usa un `<video src="{{campo}}" muted loop playsinline autoplay>`. Non aggirare `MediaStore` per renderli caricabili. Impostazioni › Caricamenti (`cms_settings.json`, `uploads`): prefisso dei nomi (per la SEO; i nomi automatici delle fotocamere come `DSC_1234` vengono ignorati, il codice finale resta), larghezza massima delle foto (predefinita 2560 px, 0 = mai) e conversione in WebP. Con l'estensione GD i JPEG vengono raddrizzati secondo l'orientamento EXIF e ricodificati senza metadati (via la posizione GPS); PNG e WebP solo se vanno ridotti o convertiti; GIF, AVIF e PDF restano come sono. Senza GD, o se la foto non sta nella memoria di PHP, il file resta com'era. Valgono anche per i caricamenti dell'agente. **Immagini ottimizzate:** con GD e WebP ogni JPEG, PNG o WebP caricato riceve varianti accanto all'originale, `<nome>-480w.webp`, `-960w.webp`, `-1600w.webp` (solo quelle più strette dell'originale; per i file dello staging alla pubblicazione). La libreria non le mostra e le elimina con l'originale; «Ottimizza le immagini» le crea per i file già caricati. Al rendering ogni `<img src="assets/uploads/…">` senza `srcset` riceve `srcset`, `sizes="100vw"` e, se mancano, `width` e `height` dell'originale: i template non devono fare nulla, e un `srcset` o un `sizes` scritti nel tema restano.

## Pagina 404

Ogni sito ha la sezione riservata **Pagina 404** nell'editor: raccolta `kris_notfound` (contenuto con ID `0`, migrazione `0006_pagina_404.php`) con `title`, `text`, `button_label`, `button_url` e `image`, con testi predefiniti in italiano e inglese. Funziona subito senza configurare niente: la 404 di serie di Kris (`kris/404.php`) usa titolo, testo e pulsante della sezione.

La 404 del tema è `template/404.html`, un template come gli altri: lo rende il motore di Kris con lo stato 404, nella lingua richiesta. Navbar, footer e altri componenti li include il template con `k-component`, se servono. Come campi usa quelli di `kris_notfound` (`{{title}}`, `{{text}}`, `{{button_label}}`, `{{button_url}}`, `{{image}}`); se il tema ha una sua raccolta `not_found` (siti precedenti alla 1.8.0), vince quella. L'agente scrive i testi della sezione con `set_field`.

Perché un indirizzo inventato (`/pagina-che-non-esiste`) arrivi a Kris serve una regola del server: l'aggiornamento crea nella root il `.htaccess` con la regola (`Stubs::HTACCESS`) **solo se non esiste**; se il sito ne ha già uno, va aggiunta a mano. La regola è una riscrittura interna, non un redirect: lo stato resta 404, funziona anche in sottocartella, e i file mancanti di `assets/` o con estensione di file rispondono con la 404 semplice di Apache. Su Nginx serve una regola equivalente (`try_files $uri $uri/ /index.php?page=404`, con `error_page` a 404 se si vuole lo stato corretto). Non va in `schema.pages` né nella sitemap: `?page=404` risponde comunque 404. Si può preparare anche dallo staging e vedere in anteprima aprendo un indirizzo inesistente con `?kris_preview=1`. Se il template ha un errore, Kris usa la 404 di riserva invece di un errore 500.

## Lavori in corso

Ogni sito ha la sezione riservata **Lavori in corso** nell'editor: raccolta `kris_wip` (contenuto con ID `0`, migrazione `0007_lavori_in_corso.php`) con `enabled` (interruttore), `title` e `text`. Con l'interruttore acceso i visitatori ricevono una pagina 503 con `Retry-After` (anche `sitemap.php`), chi ha la sessione dell'editor vede il sito normale e l'editor mostra un promemoria in ogni schermata. La pagina è `template/kris_wip.html` del tema, resa con i campi della sezione (e con `kris_head.html`), oppure quella di serie di Kris (`kris/wip.php`). Staging e agente ne scrivono i valori con `set_field`, come per la 404.

## Privacy e cookie

Ogni sito Kris ha la raccolta riservata `kris_legal`, con un solo contenuto (ID `0`) e campi fissi. Il cliente li compila da **Privacy e cookie** nell'editor; non compare tra le raccolte e non si modifica da Struttura. I template la usano solo se vogliono, come qualunque raccolta.

| Campo | Tipo | Contenuto |
| --- | --- | --- |
| `owner_name`, `owner_address`, `owner_vat`, `privacy_email` | `text` condiviso | Titolare del trattamento: nome o ragione sociale, indirizzo, P.IVA o codice fiscale, email per le richieste |
| `updated_at` | `text` condiviso | Data di ultimo aggiornamento delle informative |
| `privacy_policy`, `cookie_policy` | `richtext` | Testi delle informative |
| `banner_title`, `banner_text`, `banner_accept`, `banner_reject`, `banner_more`, `banner_customize`, `banner_save` | `text` | Testi del banner cookie |
| `category_thirdparty`, `category_statistics`, `category_marketing` e i loro `…_text` | `text` | Nome e descrizione delle categorie (migrazione `0005_categorie_cookie.php`) |

Kris Agent può preparare tutti i valori di `kris_legal`, informative e banner compresi (vedi "Staging e anteprima"): le informative sono HTML `richtext`, vanno scritte su dati e indicazioni del cliente e le rivede e pubblica l'admin; l'agente non deve inventare dati societari né cookie che il sito non usa. Lo schema lo gestisce il framework con le migrazioni (`kris/migrations/0002_privacy_e_cookie.php`): non modificarlo a mano e non aggiungere campi a `kris_legal`. Per un dato legale che manca, proponi una migrazione del framework.

**Pagine delle informative:** un template dedicato che legge l'entità `kris_legal`, per esempio `template/privacy.html` con `{{privacy_policy}}` (i `richtext` escono come HTML) e i dati del titolare. Registra la pagina in `schema.pages` (tipo `kris_legal`, `item` 0), linkala dal footer con `index.php?page=privacy&amp;key=kris_legal&amp;ln={{language}}` e aggiungila alla sitemap.

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

- Gli script non tecnici si scrivono bloccati, con la loro categoria: `<script type="text/plain" data-kris-consent-script="statistics" src="…"></script>`. Categorie: `thirdparty` (mappe, video, social incorporati), `statistics` (per esempio Google Analytics), `marketing` (per esempio Pixel di Meta). `consent.js` li esegue solo con il consenso a quella categoria; senza categoria, solo con "Accetta tutto". Non caricarli in nessun altro modo.
- Con più finalità il Garante chiede la scelta per categoria: il banner ha un secondo livello `[data-kris-consent-details]` (con `hidden`) aperto da `data-kris-consent="details"`, caselle `<input type="checkbox" data-kris-consent-category="statistics">` mai spuntate in partenza e un pulsante `data-kris-consent="save"`. Vedi `template/cookie-banner.html` della demo.
- "Rifiuta" deve essere visibile e semplice quanto "Accetta"; niente caselle preselezionate. Un link `data-kris-consent="open"` nel footer riapre il banner.
- La scelta resta nel cookie `kris_consent` per 180 giorni: `accepted` (tutto), `rejected` (niente) o `c:statistics,marketing` (solo quelle categorie). `window.KrisConsent.status()` restituisce `accepted`, `rejected`, `custom` o `null`; `KrisConsent.allows('statistics')` dice se una categoria è accettata; l'evento `kris:consent` su `document` ha `detail.accepted` e `detail.categories`. Revocare una categoria ricarica la pagina.
- Lo stile del banner è del tema, in `assets/css/`. Il banner deve restare usabile da tastiera e su mobile.
- Kris non scrive i testi legali e non sa quali cookie usa il sito: segnala al cliente cosa compilare, senza inventare informative.

## Proprietà del sito

Dalla migrazione `0008_proprieta_del_sito.php` la raccolta `kris_site` (in editor **Proprietà del sito**) ha, oltre alla favicon: `site_name`, `title_template` (`{title} | {site}`; `{title}` è il titolo della pagina), `description` e `share_image` predefinite, `google_verification` e `bing_verification` (solo il valore di `content`). Una pagina il cui tipo ha i campi `seo_title`, `seo_description` o `seo_image` usa i suoi. Kris aggiunge prima di `</head>` description, Open Graph (solo se il sito ha nome, descrizione o immagine) e verifiche, e passa il `<title>` dal modello, sempre e solo dove il template non ha già quei tag. Per dati strutturati e altri meta del tema usa `template/kris_head.html`.

## Favicon

La favicon la sceglie il cliente da **Favicon** nell'editor: raccolta riservata `kris_site` (contenuto con ID `0`), campo `favicon` di tipo `media`, creata dalla migrazione `0004_favicon.php`. Kris aggiunge da solo `<link rel="icon">` prima di `</head>` in ogni pagina e nella 404, a meno che il template non abbia già un `rel="icon"`: i template non devono fare nulla.

- Vale solo un file caricato dal CMS (`assets/uploads/…`): PNG, WebP, AVIF, GIF o JPG. Niente `.ico` né SVG. Consigliato un PNG quadrato di almeno 48×48 pixel.
- Una favicon fissa del tema si può ancora scrivere nel template (`assets/images/…`): in quel caso quella dell'editor viene ignorata. Non usare entrambe.
- Kris Agent la può preparare: `create_upload_slot`, poi `set_field` su `kris_site`, ID `0`, campo `favicon`. Anche l'editor in modalità «In attesa» la prepara. È l'unica modifica ammessa su una raccolta `kris_`.

## SEO: sitemap e indirizzi

Le pagine sono HTML completo generato sul server: i motori di ricerca le leggono come pagine statiche. Kris aggiunge da solo, prima di `</head>`, il `<link rel="canonical">` e i `hreflang` per ogni lingua, a meno che il template non abbia già un canonical. Gli URL canonici omettono i parametri con il valore predefinito (`page` e `key` `homepage`, `id` 0, lingua principale).

`sitemap.php` genera la sitemap dai contenuti a ogni richiesta: un post aggiunto dall'editor compare subito. Elenca le pagine di `schema.pages` con `"sitemap": true`:

```json
"pages": {
  "homepage": {"template": "homepage", "type": "homepage", "item": 0, "sitemap": true},
  "progetto": {"template": "progetto", "type": "progetti", "sitemap": true, "priority": 0.8},
  "privacy": {"template": "privacy", "type": "kris_legal", "item": 0, "sitemap": true}
}
```

- Con `item`: quel contenuto. Senza: una voce per ogni contenuto visibile del tipo. Con `item` e `list`: una voce per ogni elemento dell'elenco. In tutte le lingue attive.
- **Regola:** quando aggiungi una pagina navigabile, registrala in `schema.pages` (o con l'operazione `register_page`) e verifica che compaia in `sitemap.php`.
- Le opzioni del sito stanno in `settings.seo`: `base_url` va impostato in produzione (senza, l'indirizzo si ricava dalla richiesta); `"head": false` disattiva canonical e hreflang automatici; `pretty_urls`, `auto_language`, `robots` (righe aggiuntive di robots.txt, una regola per riga).
- `robots.txt` lo genera Kris (regola del `.htaccess` verso `sitemap.php?robots=1`): esclude `/kris-admin/` (e il vecchio `/editor/`), aggiunge le righe di `settings.seo.robots` e la sitemap assoluta. Un `robots.txt` fisico nella root vince. Se lo scrivi a mano, al deploy, usa l'URL assoluto della sitemap:

```text
User-agent: *
Disallow: /kris-admin/
Sitemap: https://www.esempio.it/sitemap.php
```

Non bloccare `/kris/` né `/assets/`: contengono gli script e gli stili delle pagine. Segnala al cliente di inviare la sitemap in Google Search Console.

## Editor e persistenza

L'editor è un'app Vue (`admin-src/`, compilata in `kris/editor/dist/` e versionata: sugli hosting non serve Node; la CI ricompila e fallisce se `dist/` non corrisponde ai sorgenti). Le pagine di accesso, primo avvio e reset restano PHP (`auth.php`); dopo l'accesso `index.php` carica l'app, che parla con `api.php` (`index.php?api=<nome>`: GET per leggere, POST JSON con l'intestazione `X-Kris-Csrf`). **Ogni scrittura su contenuti e struttura è un'operazione di `Kris\Staging\Operations`**: l'app le calcola (`admin-src/src/lib/model.js`, come `EditorChanges`) e l'API le applica nella modalità in uso: sul sito pubblico con `Kris\Staging\Live` (transazione dell'archivio, tutte o nessuna), in «In attesa» registrandole nello staging. La Struttura arriva intera e diventa operazioni campo per campo (`editorSchemaOps` in `model.php`); se cancellerebbe testi già scritti l'API risponde 409 con il riepilogo e l'app chiede conferma. Struttura, lingue, indirizzi ed eliminazione dei file si cambiano solo sul sito pubblico; i tipi `plg_` non si toccano dall'API. Nell'app: una cartella per funzione in `src/views/pages/`, chiamate HTTP solo nei service (`src/services/`), stato condiviso in Pinia (`src/stores/`), rotte con `#` (nessuna regola di riscrittura sugli hosting).

Mantieni autenticazione e CSRF per ogni chiamata che scrive. Le scritture confermano il risultato del server prima di mostrare successo; gli errori conservano i campi compilati. Le modifiche non salvate di una pagina chiedono conferma prima di uscire (`src/components/dirty.js`). Riordino, visibilità ed elementi degli elenchi si salvano subito; l'ordine si cambia trascinando dalla maniglia o con le frecce sulla maniglia.

**Utenti, accesso e recupero della password.** Ci sono due ruoli: `admin` e `base`. L'unico potere in più dell'admin è gestire gli utenti (Impostazioni › Utenti): li crea con nome, email e password, li blocca temporaneamente o li elimina, cambia il ruolo o reimposta una password; deve restare almeno un admin attivo e nessuno blocca o elimina sé stesso. Bloccare, eliminare o cambiare la password di un utente chiude subito le sue sessioni. Il setup chiede il codice di primo avvio e crea l'amministratore. Il codice lo crea Kris in `config/setup_code.php` (un file PHP, quindi non scaricabile) e lo legge solo chi ha accesso ai file del sito: senza, chiunque aprisse per primo l'editor creerebbe l'admin. Creato l'accesso il file si elimina; i codici sbagliati contano come accessi sbagliati. Ogni utente, in Impostazioni › Account, cambia la sua email e la sua password (entrambe con la password attuale: l'email riceve i link di reset) e si invia un'email di prova. "Password dimenticata?" chiede l'email e manda all'utente che la usa un link monouso valido 30 minuti, costruito sull'indirizzo dell'editor salvato da un utente autenticato e mai sull'host della richiesta; la risposta è la stessa se l'email non esiste. L'invio usa `mail()` di PHP: su alcuni hosting non arriva. In quel caso un admin reimposta la password da Impostazioni › Utenti; se nessuno può accedere, l'amministratore dell'hosting elimina `config/users.php` (riparte il setup, con un codice nuovo in `config/setup_code.php`; i contenuti restano).

**Tema chiaro e scuro.** L'editor segue il tema del sistema; il pulsante «Tema» nella barra laterale lo forza su scuro o chiaro (`data-theme` su `<html>`, scelta salvata nel browser in `localStorage`, chiave `kris-theme`). I colori di `admin-src/src/assets/styles.css` sono variabili in `:root`, ridefinite per il tema scuro: nelle regole nuove usa le variabili, non colori scritti a mano.

L'editor permette di configurare anche gli schemi `of` e presenta l'impatto delle modifiche distruttive. I nomi dei campi devono continuare a corrispondere ai template. Non introdurre messaggi che promettono bozze, undo o gestione conflitti non implementati.

## Staging e anteprima

Le modifiche preparate senza pubblicarle subito (dall'agente di Kris Agent, dall'editor in modalità «In attesa» o da `tools/staging.php`) vanno in `data/staging/`, che ricalca la struttura del sito e contiene solo i file modificati.

- **Regola:** dallo staging (e quindi dall'agente) si cambia tutto ciò che è del sito, struttura dati e tema; non si tocca il core, cioè `kris/` (framework ed editor), gli stub e la configurazione privata.
- **File del tema:** si possono scrivere ed eliminare `template/<nome>.html`, `assets/css/<nome>.css`, `assets/js/<nome>.js` e `assets/images/<nome>.svg`. Tutto il resto viene rifiutato da `FilePolicy`: framework, stub, PHP, `.htaccess`, `config/`, `data/`. Niente tag PHP nei contenuti (un SVG può aprirsi con la dichiarazione XML). Le immagini raster passano dall'upload e finiscono in `assets/uploads/`. I **font del tema** (solo `.woff2`, fino a 2 MB, con la firma `wOF2`) passano dallo stesso caricamento (`Staging::uploadFont`, scelto dall'estensione): nello staging stanno in `data/staging/assets/fonts/`, alla pubblicazione vanno in `assets/fonts/<nome>.woff2` con un nome leggibile e stabile da richiamare nel `@font-face`; un font con lo stesso nome viene sostituito. Ospitare i font sul sito evita di mandare l'IP dei visitatori a Google Fonts senza consenso. Un file eliminato resta nel sito fino alla pubblicazione; il template di una pagina pubblica non si elimina finché la pagina è registrata, e un frammento eliminato ma ancora usato da un `k-template` blocca la pubblicazione.
- **Script da approvare:** JavaScript, SVG e template che aggiungono script, gestori di eventi o URL `javascript:`, e i testi `richtext` scritti dalle operazioni con gli stessi elementi, sono codice che gira nel browser dell'admin, con la sessione dell'editor aperta. Finché l'admin non lo approva, l'anteprima manda una `Content-Security-Policy` che lascia girare solo gli script di Kris (barra e popup, con nonce) e mostra un popup con l'elenco dei file: «Approva ed esegui gli script», dopo aver spuntato l'assunzione di responsabilità, oppure «Guarda senza script». L'approvazione (`Staging::approveCode`, in `data/staging/code_approval.json`) vale per quella versione del codice: se un file cambia, il popup torna. Si approva anche da Editor › Da pubblicare.
- **Contenuti, schema e pagine:** non si copiano i file. Si registrano operazioni (`Kris\Staging\Operations`: `set_field`, `add_item`, `remove_item`, `move_item`, `create_entity`, `remove_entity`, `move_entity`, `set_hidden`, `add_field`, `remove_field`, `rename_field`, `describe_field`, `change_type`, `move_field`, `set_options`, `create_collection`, `rename_collection`, `remove_collection`, `set_post_collection`, `register_page`, `unregister_page`, `set_sitemap`) in `data/staging/operations.json`. `register_page` scrive la pagina in `schema.pages`: `sitemap` assente la lascia com'è (una pagina nuova rende il contenuto 0 del tipo con il suo nome, o della homepage, fuori dalla sitemap), `false` la toglie dalla sitemap, `true` ce la mette, una regola (`key`, `id`, `children`: tipo, contenuto fisso, elenco) dice anche cosa rende; `set_sitemap` (`page`, `sitemap`) fa lo stesso su una pagina già pubblica. `add_field` e `change_type` usano i tipi del formato 2 con `localized` (o i nomi della 1.x); `change_type` converte i valori (condiviso ↔ per lingua, text ↔ richtext) e da o verso `list`, `boolean` o `select` solo se il campo è vuoto ovunque. `set_options` (`name`, `options`, `multiple`) cambia le opzioni di una scelta e toglie dai contenuti quelle non più ammesse. Posts è `set_post_collection` (un elenco non ci va più: `set_posts` è rifiutata); rinominare o eliminare una raccolta aggiorna anche le pagine che la rendono (eliminandola, le toglie) e gli elenchi promossi, e un template che la usa ancora blocca la pubblicazione. Alla pubblicazione si rieseguono sui dati attuali del sito: le modifiche fatte intanto dall'editor restano. Se il cliente ha cambiato lo stesso valore, o l'elemento non esiste più, è un conflitto. Rinomine e rimozioni aggiornano anche i valori; un template che usa ancora un campo tolto blocca la pubblicazione. Il controllo considera solo i template che rendono il tipo del campo, ricavato dalle pagine dello schema, dai link `page=…&key=…`, da `k-component` e `k-array` (`Kris\Staging\TemplateUsage`): un campo omonimo di un'altra raccolta non conta. Un template a cui non arriva nessun riferimento viene controllato comunque. I tipi `kris_` non si toccano, tranne con `set_field` i valori di `kris_site` (favicon e proprietà del sito), di `kris_notfound` (la pagina 404), di `kris_wip` e tutti i valori di `kris_legal` (dati del titolare, informative, testi del banner e delle categorie cookie). Lo schema di `kris_legal` e di `kris_site`, la loro creazione ed eliminazione no. Un elemento o contenuto nuovo tiene alla pubblicazione l'id avuto nell'anteprima: se sul sito nel frattempo è stato preso, è un conflitto. Più modifiche consecutive dello stesso campo diventano una sola, che conserva il valore di partenza per il controllo dei conflitti; se il campo torna al valore di partenza, la modifica sparisce. `add_item` e `create_entity` rifiutano le chiavi che non conoscono (un `data` al posto di `fields` creerebbe un elemento vuoto); in `fields` (valori per nome, come in `content.json`) un elenco annidato si passa come lista di elementi, ognuno con i suoi campi, e gli elementi ricevono gli id 0, 1, 2… nell'ordine dato. Il testo `richtext` si salva filtrato (vedi "Contratto dei template"). Un testo solo su un campo multilingua vale per la lingua principale e svuota le altre: l'operazione riesce, con un avviso che elenca le lingue rimaste vuote (`Staging::lastWarnings`). `Staging::operateAll` registra più operazioni insieme, tutte o nessuna; il rifiuto dice quale operazione del gruppo non è valida.
- **Editor › In attesa:** il selettore in alto («Sito pubblico» / «In attesa») cambia dove salva l'editor. In attesa l'editor mostra lo staging, comprese le modifiche dell'agente, e ogni azione sui contenuti diventa operazioni (`EditorChanges`, come sul sito pubblico): salvataggio campo per campo e lingua per lingua, elementi aggiunti, eliminati, riordinati e sospesi. Gli upload vanno in `data/staging/uploads/` e l'editor li mostra attraverso l'anteprima. Struttura, lingue, eliminazione di file e «Privacy e cookie» restano solo sul sito pubblico.
- **Immagini:** `Staging::upload()` usa `MediaStore::storeLocal()`, con gli stessi controlli dell'upload dell'editor, e salva in `data/staging/uploads/`. La pubblicazione le copia in `assets/uploads/`. `Staging::deleteUpload()` toglie dallo staging un file caricato e non ancora pubblicato (anche un font), senza buttare via il resto; non se è ancora il valore di un campo in attesa. I file già sul sito si tolgono dall'editor.
- Ogni scrittura porta l'impronta (sha256) della versione letta: se il file è cambiato nel frattempo, la scrittura viene rifiutata, con l'impronta attuale nei dettagli (`current_hash`). `edit` sostituisce un testo che deve comparire una sola volta.
- **Avvisi:** `Staging::warnings()` segnala, senza bloccare la pubblicazione, i campi senza sezione accanto a campi che ce l'hanno, i campi che nessun template usa e i font citati dal CSS che non ci sono in `assets/fonts/`.
- **Anteprima:** `/kris-admin/preview/` seguito dall'indirizzo della pagina (`/kris-admin/preview/en/menu` con gli indirizzi leggibili, `/kris-admin/preview/?page=menu` senza), oppure `index.php?kris_preview=1`, solo con la sessione dell'editor. Aperta una volta, resta attiva in quel browser (cookie `kris_preview_mode`, `Preview::MODE_COOKIE`) anche per gli indirizzi scritti a mano, così si vedono le pagine che nessun link raggiunge; «Esci» porta a `?kris_preview=0`, che la chiude. Senza sessione il cookie da solo non apre nulla. `Staging::previewPages()` elenca le pagine toccate dalle modifiche in attesa, ognuna con il suo indirizzo di anteprima: le mostra «Da pubblicare» e le restituisce lo `status` dell'agente (`preview_pages`). I file in attesa si servono da `index.php?kris_preview_asset=…&kris_preview=1` (`Preview::assetUrl`: l'indirizzo non finisce con l'estensione, così i proxy degli hosting non lo trattano come file statico); nel CSS in attesa gli `url()` e gli `@import` relativi vengono riscritti dalla root del sito. Senza sessione la pagina risponde 404. La pagina ha `noindex`, `no-store` e una barra sottile fissa in cima che dice che è un'anteprima (anche senza modifiche in attesa).
- **Pubblicazione:** da Editor › Da pubblicare. Riepilogo con diff, segnalazione di script, gestori di eventi, URL `javascript:` e domini esterni aggiunti. Se un file è cambiato sul sito dopo la preparazione, serve una conferma esplicita. Prima di scrivere si salvano le versioni attuali: l'ultima pubblicazione si annulla per 14 giorni.
- Con modifiche in attesa l'aggiornamento di Kris è bloccato: prima si pubblica o si scarta.

## Kris Agent

Un assistente AI esterno (via MCP, backend nella repository `kris-agent`) prepara modifiche nello staging. Non pubblica mai: pubblica l'admin del sito.

- **Abbinamento:** Editor › Impostazioni › Kris Agent. L'admin incolla il codice generato nel pannello di Kris Agent: vale 30 minuti, una volta, e contiene indirizzo dell'API, id del sito e un segreto. Il sito crea le proprie chiavi Ed25519 (`sodium`), le scambia con il Worker e salva tutto in `config/agent.php`. Il Worker accetta solo se l'indirizzo del sito è quello registrato nel pannello. Dall'editor l'agente si spegne, si riaccende e si scollega.
- **Richieste:** arrivano su `kris-agent.php` (`Kris\Agent\Server`), firmate dal Worker. La firma copre `KRIS-AGENT-1`, metodo, id del sito, ora, nonce e sha256 del corpo. Ora entro 5 minuti, nonce mai ripetuti (`data/agent_nonces.json`), chiave dell'abbinamento in corso.
- **Azioni ammesse:** `ping`, `overview`, `read_file`, `search_theme`, `write_file`, `edit_file`, `delete_file`, `get_entity`, `operate`, `operate_batch` (fino a 100 operazioni, tutte o nessuna), `upload`, `delete_upload`, `render_page`, `site_config`, `status`, `discard`. `read_file` accetta `from_line` e `lines` (al massimo 400 righe), `search` (solo le righe che contengono il testo) e `hash_only`; l'impronta è sempre quella del file intero. `search_theme` cerca un testo in template, CSS, JS e SVG (al massimo 200 righe). `edit_file` senza `expected_hash` riesce se il testo da sostituire compare una volta sola. `render_page` (`page`, `key`, `id`, `path`, `ln`) restituisce stato e HTML finito della pagina con le modifiche in attesa, compreso quanto Kris aggiunge da solo, senza la barra dell'anteprima (`Kris\Site\PageRenderer`). `site_config` restituisce in sola lettura pagine, opzioni SEO, reindirizzamenti e sitemap con le modifiche in attesa e `robots.txt`. `overview` descrive lingue, pagine e tipi del formato 2 (con i loro attributi), contenuti e file del tema; `get_entity` restituisce il contenuto con i valori per nome. `operate` e `operate_batch` restituiscono gli avvisi in `warnings`; `status` e `overview` gli avvisi dello staging. Tutte lavorano sullo staging; non esiste un'azione per pubblicare né per approvare gli script.
- **Chi può usare l'agente:** nella stessa scheda l'admin del sito vede gli utenti con un abbonamento sul sito (e gli admin di piattaforma, che possono sempre), li approva o li esclude, e reimposta l'accesso: tutti tornano in attesa e le chiavi di entrambe le parti cambiano. Sono richieste del sito al Worker (`Kris\Agent\WorkerClient`, rotte `/api/v1/site-api/*`), firmate con la chiave del sito con lo stesso protocollo. Le chiavi nuove si salvano solo se il Worker conferma.
- Le richieste in uscita (abbinamento e richieste al Worker) passano da `Kris\Agent\HttpPost`: https obbligatorio, http solo verso localhost per lo sviluppo.

## Aggiornamenti del framework

Da un sito installato l'admin aggiorna Kris da Impostazioni › Versione di Kris: verifica, scaricamento o caricamento dello zip, installazione. Il flusso è in `kris/update/`.

- **Canale:** `releases.json` e `releases.json.sig` su `main`, letti da raw.githubusercontent.com. Mai l'API di GitHub. Solo `https` e host in `Http::ALLOWED_HOSTS`.
- **Firme:** Ed25519. Le chiavi pubbliche stanno in `kris/update/keys.php`; le segrete fuori dalla repository. Un pacchetto è uno zip della sola `kris/` con `RELEASE.json`, `MANIFEST.json` (sha256 di ogni file) e `MANIFEST.sig`: si verifica da solo, anche caricato a mano.
- **Policy:** dall'editor si installano versioni non `breaking` della stessa major e, dalla 1.10, della major successiva se la release lo dichiara (`"editor_upgrade": true`, `tools/release.php --editor-upgrade`, di solito con `--min-from` sull'ultima della major precedente e `--requires-php`). Prima del salto l'editor dice cosa cambia e avvisa di `.htaccess` senza la regola di Kris e stub di `editor/` modificati (`Updater::upgradeWarnings`). Le altre major le installa lo sviluppatore.
- **Avviso nell'editor:** aperto l'editor, se l'ultimo controllo ha più di un giorno il browser rilegge il canale dopo il caricamento (`update.php`, `op=check`, che registra l'esito in `kris_state.json`); se c'è una versione installabile compare «È disponibile Kris X» con «Aggiorna ora» (scheda Versione di Kris) e «Più tardi» (per quella versione, in quel browser: `localStorage`, chiave `kris-update-later`). Errori del canale: nessun avviso. Spento con `config/update.php` disattivato.
- **Installazione:** preflight reale (scrittura e spostamento di cartelle), controllo di sintassi, snapshot di `data/*.json` e `config/`, manutenzione, scambio di `kris/`, migrazioni, verifica delle pagine con i contenuti veri. A ogni errore, anche fatale, il sito torna com'era da solo.
- **Contratto con le versioni installate:** `installer.php` espone `kris_update_install(array $ctx): array` e arriva dentro il pacchetto nuovo; i campi di `releases.json` e `RELEASE.json`, i percorsi in `data/` e il formato di `kris_maintenance.json` si possono estendere, mai rinominare. Un sito vecchio legge il canale con il suo codice.
- **`bootstrap.php`** viene incluso di nuovo dall'installer dopo lo scambio: niente dichiarazioni che non tollerino la ripetizione.

**Cambiare il formato dei dati richiede una migrazione** in `kris/migrations/`: numerata, solo in avanti, funzione pura su `['schema', 'content', 'settings']` (fino alla 0009 i documenti della 1.x: `k_data`, `k_model`, `cms_settings`, più `allowed_pages`, `seo` e `templates` in lettura), senza usare classi del core, idempotente, con un test fixture prima → fixture dopo. `'auto' => true` solo se non cambia i contenuti. Non è una migrazione la rinomina di un campo di un sito cliente: quella è contenuto del sito.

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

La suite dell'archivio nel database richiede `pdo_sqlite`. Le suite dei pacchetti e dell'installazione richiedono le estensioni `sodium` e `zip`: se mancano vengono saltate e il riepilogo lo dice (`SALTATA`). Con un PHP che non le carica dal php.ini: `php -d extension=sodium -d extension=zip tests/run.php`. Una suite saltata non è una suite passata.

Apri `http://127.0.0.1:8000/` e `/kris-admin/`. Il setup crea l'amministratore se non ci sono utenti. Per lavorare sull'editor: `npm install` e `npm run dev` in `admin-src/` con `VITE_PROXY_TARGET=http://127.0.0.1:8000` e `VITE_API_URL=/kris-admin/index.php` nel `.env`; prima di consegnare `npm run build` e versiona `kris/editor/dist/`. Il server PHP integrato serve solo allo sviluppo locale.

Sulla linea 2.0 serve almeno PHP 8.3 (la 1.x resta su 8.0): `readonly`, `enum` e il resto di 8.3 sono ammessi, niente di 8.4. La CI verifica 8.3 e 8.4. Servono DOM/libxml, mbstring e le funzionalità standard JSON e sessioni; verifica inoltre la disponibilità delle funzioni di controllo immagini usate da `MediaStore`. Per aggiornare dall'editor servono anche `sodium` e `zip`, e cURL (o `allow_url_fopen`) con i certificati configurati: senza, resta il caricamento manuale del pacchetto oppure l'aggiornamento via FTP.

Prima di consegnare una conversione:

- Verifica sintassi PHP dei file modificati e sintassi JS con `node --check` dove applicabile. Esegui `php tests/run.php` se tocchi framework, editor o rendering.
- I test pubblici usano `tests/fixtures/`, non i dati reali: il passaggio della suite non prova da solo che il nuovo sito del cliente funzioni. Controlla tutte le nuove pagine e i relativi contenuti reali in un ambiente di sviluppo.
- Verifica desktop e mobile, tastiera, focus, contrasto, menu, link, immagini, titoli/meta description, lingua del documento e 404. Confronta il risultato renderizzato con Figma o HTML di riferimento.
- Prova nell'editor login, salvataggio, upload e riordino per i contenuti introdotti; usa copie isolate per prove distruttive. Non usare i contenuti del cliente come fixture.
- Aggiorna gli snapshot soltanto per cambiamenti intenzionali, con `php tests/run.php --update-snapshots`, e controlla il diff. Non rigenerarli per nascondere regressioni.

Per pubblicare:

- Lo zip da caricare via FTP lo prepara `php tools/package.php`: con `--first` per il primo caricamento (dati iniziali, upload, `.htaccess`), senza opzioni per gli aggiornamenti (senza `data/`, `config/` e upload del cliente). Non contiene mai configurazione privata, stato del framework, backup, staging, test, documenti, strumenti o `admin-src/`.

- L'hosting deve eseguire PHP e puntare alla root del progetto. Non serve Composer: l'autoload è in `kris/bootstrap.php`. Non servono processi Node in produzione per il tema HTML/CSS/JS.
- Al primo rilascio includi template, asset, configurazione delle pagine, schema e contenuti iniziali. Nei rilasci successivi preserva `config/`, tutto `data/` (compresi `kris_state.json`, backup e snapshot) e gli upload; applica modifiche allo schema con una migrazione coerente e copia di sicurezza. Aggiornando il framework via FTP sostituisci la cartella `kris/` intera, non file per file: l'editor registra il cambio di versione e applica o propone le migrazioni.
- PHP deve poter scrivere in `data/`, nella directory dei backup e in `assets/uploads/`; per il setup iniziale deve poter creare `config/users.php`; per aggiornare dall'editor deve poter spostare `kris/` nella root del sito. Usa permessi appropriati all'hosting, non `777` come soluzione generica.
- Verifica sul server che `config/` e `data/` non siano scaricabili e che gli upload non eseguano codice. Gli `.htaccess` presenti sono specifici di Apache; Nginx e altri server richiedono regole equivalenti. Verifica la compatibilità delle direttive upload con l'hosting effettivo.
- Escludi dall'artefatto pubblico `.git/`, configurazioni locali degli agenti, test e documenti di sviluppo. Configura HTTPS e verifica login, persistenza e asset sul percorso finale, anche se il sito vive in sottocartella.
- Dopo il rilascio controlla homepage, una pagina di dettaglio, lingua alternativa, 404, `sitemap.php`, `robots.txt` e accesso all'editor. Da Impostazioni › Account invia un'email di prova e chiedi al cliente se è arrivata: se no, il recupero della password passerà dall'amministratore dell'hosting. Non sovrascrivere le credenziali e non lasciare un setup pubblico non configurato.

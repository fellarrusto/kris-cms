# Kris 2.0: plugin

Stato: implementata nella fase 7 (`api` 1): `kris/core/plugin/` (`Kris\Plugin\Plugins`, `Context`, `Items`, `Manifest`, `Installer`, `PluginHttp`), API e schermate dell'editor, `tools/plugin-release.php`. Traduce in contratti le decisioni di `piano.md` §6 e i vincoli di `specifica.md` §4. Dove diverge dal piano vale questa specifica.

## 1. Cos'è un plugin

Un plugin è codice PHP che aggiunge al sito una funzione che non serve a tutti i siti e spesso nomina un fornitore: misurazione con GA4, un modulo contatti, IndexNow. Il core resta generico: se una funzione serve a qualunque sito va nel core, altrimenti diventa un plugin.

Un plugin:

- ha il suo codice fuori da `kris/`, che gli aggiornamenti sostituiscono;
- aggiunge al sito tag, script, blocchi nei template ed endpoint HTTP;
- aggiunge all'editor impostazioni, voci, riquadri e schermate intere: moduli Vue compilati che usano lo stesso Vue e il kit dei componenti dell'editor, senza limiti di complessità (un e-commerce, un calendario delle prenotazioni);
- conserva i suoi dati in tipi privati (`plg_<nome>_*`) attraverso l'archivio, mai con file propri in `data/` o tabelle proprie;
- arriva solo dalle repository ufficiali, firmato con le chiavi del core.

Su un hosting condiviso PHP non ha una sandbox: un plugin installato ha gli stessi permessi di Kris. Il confine di sicurezza è la revisione di ciò che entra nelle repository e la firma. L'isolamento qui sotto serve a tenere accessibili sito ed editor quando un plugin si rompe, non a contenere un plugin ostile.

## 2. File

### 2.1 Sul sito

| Percorso | Contenuto |
| --- | --- |
| `plugins/<nome>/` | Il pacchetto installato: manifest, codice, viste, migrazioni. Si sostituisce in blocco a ogni aggiornamento del plugin. |
| `assets/plugins/<nome>/` | I file pubblici del plugin (CSS, JS, immagini, font), copiati dalla cartella `public/` del pacchetto all'installazione. |
| `data/kris_plugins.json` | Stato dei plugin: installati, versione, attivo o no, migrazioni applicate, ultimo errore. Lo scrive solo il core. |
| `data/kris_plugin_logs/<nome>.log` | Registro del plugin (ultime 200 righe), visibile nella scheda Plugin. |
| `data/kris_plugin_rate.json` | Richieste recenti agli endpoint, per il limite di frequenza (IP non in chiaro). |
| `config/plugin_key.php` | Chiave dei gettoni dei moduli, creata al primo uso. Privata, esclusa da Git. |
| `config/plugins.php` | Facoltativo: `return ['safe_mode' => true];` spegne tutti i plugin (modalità sicura); `'enabled' => false` toglie i plugin dall'editor. |

`plugins/` non è servita direttamente: la prima installazione crea `plugins/.htaccess` (`Require all denied`), e ogni file PHP del pacchetto si apre con `defined('KRIS_ROOT') || exit;` (l'installazione lo controlla), così anche su Nginx senza regole un file chiamato dal browser non fa niente. `tools/package.php` non mette nel pacchetto FTP né `plugins/` né `assets/plugins/`: i plugin si installano dall'editor di ogni sito. In `assets/plugins/` l'installazione copia solo `.css`, `.js`, `.svg`, `.png`, `.jpg`, `.webp`, `.avif`, `.gif`, `.woff2`; niente PHP né `.htaccess`.

Gli asset del plugin ricevono `?v=` come quelli del tema (`Kris\Template\Assets`).

### 2.2 Il pacchetto

```text
plugin/
  plugin.json          manifest
  plugin.php           punto di ingresso: restituisce una funzione
  src/                 classi del plugin (namespace Kris\Plugins\<Nome>)
  templates/           frammenti HTML per i blocchi nei template
  migrations/          NNNN_nome.php, sui propri tipi
  public/              file pubblici, copiati in assets/plugins/<nome>/
    editor/            il modulo Vue compilato delle schermate (index.js, style.css)
  MANIFEST.json        sha256 di ogni file
  MANIFEST.sig         firma Ed25519 di MANIFEST.json
```

Lo zip contiene la sola cartella `plugin/` con `MANIFEST.json` e `MANIFEST.sig`, come il pacchetto del core contiene `kris/`. La verifica riusa `Kris\Update\Package` (`extract` con la radice `plugin/`, `verifyFiles`: limiti di dimensione, percorsi, firma, hash di ogni file). PHP solo in `plugin.php`, `src/` e `migrations/NNNN_nome.php`; niente `.htaccess` né estensioni eseguibili alternative; in `public/` solo le estensioni ammesse. I sorgenti delle schermate (`editor/src/`, `vite.config.mjs`) stanno nella repository del plugin, fuori da `plugin/`: nel pacchetto va solo il compilato.

## 3. Manifest

`plugin.json`:

```json
{
  "api": 1,
  "name": "misurazione",
  "title": "Misurazione",
  "description": "Google Analytics 4 e Pixel di Meta con Consent Mode v2, eventi di contatto.",
  "version": "1.2.0",
  "requires": {"kris": "2.0.0", "php": "8.3", "extensions": []},
  "types": ["plg_misurazione_settings"],
  "settings": [
    {"name": "ga4_id", "type": "text", "localized": false, "description": "ID di misurazione GA4 (G-XXXXXXX)", "section": "Google Analytics"},
    {"name": "contact_events", "type": "boolean", "localized": false, "description": "Registra i clic su telefono, WhatsApp e mappe"}
  ],
  "hooks": ["head", "body_end"],
  "editor": {"module": null, "views": [], "dashboard": null, "actions": []},
  "endpoints": [],
  "cookies": [
    {"name": "_ga", "duration": "2 anni", "provider": "Google Ireland Ltd.", "category": "statistics", "purpose": "Distingue i visitatori"}
  ],
  "domains": ["www.googletagmanager.com", "www.google-analytics.com"],
  "database_recommended": false
}
```

| Chiave | Regola |
| --- | --- |
| `api` | Versione di questo contratto. Kris installa solo le versioni che conosce; oggi `1`. |
| `name` | `[a-z][a-z0-9_]{1,30}`, uguale al nome della repository senza il prefisso `kris-plugin-` (con `-` al posto di `_`). Dà il prefisso dei tipi e la cartella. |
| `title`, `description` | Per l'editor e il catalogo; italiano, una riga la descrizione. |
| `version` | Semver, uguale al tag della release senza `v`. |
| `requires` | Versione minima di Kris e di PHP, estensioni richieste. Un requisito mancante blocca l'installazione e lo dice. |
| `types` | Tutti i tipi che il plugin crea, ciascuno con prefisso `plg_<name>_`. Le migrazioni non possono toccarne altri. |
| `settings` | Campi del pannello di impostazioni, con i tipi del formato 2 (`specifica.md` §2). Il core crea il tipo `plg_<name>_settings` a contenuto unico con questi campi. |
| `hooks` | Gli agganci che il plugin registra (§4). Registrarne uno non dichiarato è un errore. |
| `editor` | Le schermate dell'editor (§6): `module` (il modulo in `public/`, per esempio `editor/index.js`), `style` (facoltativo, `editor/style.css`), `views` (`name`, `title`, `icon`, `nav`), `dashboard` (il nome di una vista per il riquadro della dashboard), `actions` (le azioni sul server, agganci `editor:<azione>`), `settings_tab` (falso per non mostrare le impostazioni). Le voci si leggono dal manifest senza eseguire codice del plugin. |
| `endpoints` | Endpoint HTTP (§4.4): `name`, `methods` (`GET`, `POST`), `rate` (`5/10m`: richieste per IP nella finestra, in `s`, `m` o `h`; predefinito `30/1m`), `form_token`. Ogni endpoint è anche l'aggancio `endpoint:<nome>`. |
| `cookies` | Ogni cookie che il plugin imposta o fa impostare: nome, durata, fornitore, categoria (`technical`, `thirdparty`, `statistics`, `marketing`), finalità. |
| `domains` | I domini esterni da cui il plugin carica risorse o a cui manda dati. Mostrati all'installazione e nel riepilogo privacy. |
| `database_recommended` | Vero se il plugin scrive spesso (prenotazioni): l'editor consiglia l'archivio nel database. |

Il manifest è la descrizione che l'admin vede prima di installare. Il core verifica che il comportamento resti dentro il dichiarato dove può farlo: agganci, tipi, endpoint, file pubblici. Cookie e domini non si possono verificare dal server: li controlla la revisione della repository.

## 4. Agganci del sito

### 4.1 Punto di ingresso

```php
<?php
defined('KRIS_ROOT') || exit;

use Kris\Plugin\Context;

return function (Context $kris): void {
    $kris->on('head', fn(array $page) => $kris->script('assets/plugins/misurazione/gtag.js', 'statistics'));
};
```

`plugin.php` restituisce una funzione che riceve il `Context` del plugin e registra gli agganci. Viene incluso solo quando serve (§5): una richiesta della pagina pubblica, un endpoint del plugin, un evento. Niente codice a livello di file oltre alla funzione.

### 4.2 `Context`

| Metodo | Cosa fa |
| --- | --- |
| `on(string $hook, callable $fn)` | Registra un aggancio dichiarato nel manifest. |
| `settings(): array` | I valori del pannello di impostazioni, per nome (localizzati nella lingua della richiesta). |
| `items(string $type)` | I contenuti di un proprio tipo (`Kris\Plugin\Items`): `get(id)`, `list(where, limit, offset)`, `save(['id'?, 'hidden'?, 'values'])`, `delete(id)`, `atomic(fn)` (tutto o niente sotto il lock dell'archivio, per prenotazioni e contatori). Un contenuto è `{id, hidden, values}` con i valori per nome. Un tipo fuori da `types` è rifiutato. |
| `read(string $type, int $id = 0)` | Un contenuto del sito in sola lettura, con i valori nella lingua della richiesta (non `kris_`, non dei plugin), come `{{tipo.campo}}`; null se non c'è. |
| `formToken(string $endpoint)` | Il gettone per un modulo verso un endpoint con `form_token`, da mettere nel campo `kris_form_token`. |
| `script(string $src, string $category, array $attrs = [])`, `tag(string $html, string $category)` | HTML da restituire a `head` e `body_end`, già bloccato secondo il consenso (§4.3). |
| `render(string $template, array $values)` | Rende un frammento di `templates/` con il motore di Kris: `{{campo}}` escapati, condizioni, nessun PHP. |
| `url(string $path)` | Indirizzo pubblico di un file in `assets/plugins/<nome>/` o di un endpoint del plugin. |
| `language()`, `languages()`, `siteUrl()` | Lingua della richiesta, lingue attive, indirizzo del sito (da `settings.seo.base_url` o dalla richiesta). |
| `log(string $message)` | Una riga nel registro del plugin, visibile nella sua scheda dell'editor (ultime 200 righe). |
| `http()` | Richieste in uscita solo https e solo verso `domains` (IndexNow, API dei fornitori), con timeout. |

Il `Context` è l'unica interfaccia garantita fra versioni di Kris dentro la stessa `api`. Un plugin che usa classi del core fuori da `Kris\Plugin\` lo fa a suo rischio e la revisione della repository lo rifiuta.

### 4.3 Agganci

| Aggancio | Riceve | Restituisce | Quando |
| --- | --- | --- | --- |
| `head` | la pagina (`page`, `type`, `id`, `lang`, `status`) | HTML da mettere prima di `</head>` | Ogni pagina pubblica, anche 404 e lavori in corso, dopo `kris_head.html` |
| `body_end` | la pagina | HTML da mettere prima di `</body>` | Come `head` |
| `block:<nome>` | la pagina e gli attributi del segnaposto | HTML del blocco | Un `<div k-plugin="<plugin>:<nome>"></div>` nel template |
| `endpoint:<nome>` | la richiesta (§4.4) | una risposta | `index.php?kris_plugin=<plugin>&endpoint=<nome>` |
| `published` | file e operazioni pubblicate, pagine toccate con il loro indirizzo | niente | Dopo una pubblicazione dello staging riuscita |
| `content_saved` | tipo, id, lingua, campi cambiati | niente | Dopo un salvataggio dall'editor sul sito pubblico (non in «In attesa») |

- **Consenso.** `technical` vuol dire «non richiede consenso» e non è una categoria di `consent.js`. `script()` e `tag()` con un'altra categoria escono bloccati come gli script del tema (`type="text/plain" data-kris-consent-script="<categoria>"`): `consent.js` li esegue dopo il consenso. Un plugin non scrive mai un `<script>` eseguibile non tecnico. Consent Mode v2 è un caso previsto: lo script tecnico imposta il default negato e ascolta l'evento `kris:consent`.
- **Blocchi.** Il segnaposto `k-plugin` è sostituito dall'HTML del blocco, come `k-component`. Plugin assente o spento: il segnaposto sparisce. Così la cookie policy automatica è un blocco che il tema mette nella pagina dell'informativa.
- **Eventi.** `published` e `content_saved` partono dopo la risposta all'admin quando l'hosting lo consente (`fastcgi_finish_request`), altrimenti prima, con un tempo massimo di 5 secondi per plugin.
- Non ci sono agganci che modificano l'HTML della pagina già reso, il rendering dei campi o le query: si aggiungono solo se un plugin di prima parte ne dimostra il bisogno.

### 4.4 Endpoint

Per i moduli e le chiamate dal browser. L'indirizzo è `index.php?kris_plugin=<plugin>&endpoint=<nome>` (con gli indirizzi leggibili `/kris-plugin/<plugin>/<nome>`).

```json
"endpoints": [{"name": "invia", "methods": ["POST"], "rate": "5/10m", "form_token": true}]
```

Il core prima di chiamare il plugin:

- controlla metodo e limite di frequenza per IP (IP non salvato in chiaro, come `LoginThrottle`), e risponde 429 oltre il limite;
- con `form_token`, verifica il gettone del modulo: `$kris->formToken()` lo genera nella pagina (HMAC con una chiave del sito, valido 2 ore, legato al plugin e all'endpoint); i visitatori non hanno una sessione, quindi non è il CSRF dell'editor;
- limita il corpo a 64 KB e rifiuta i caricamenti di file (non previsti nella `api` 1).

L'aggancio riceve `method`, `query`, `body` (campi del modulo o JSON decodificato), `lang` e restituisce `['status' => 200, 'json' => …]`, `['status' => 200, 'text' => …]` (testo semplice: la chiave di IndexNow) oppure `['status' => 303, 'location' => '/percorso']` (solo percorsi del sito: un indirizzo verso un altro dominio è rifiutato). Kris non manda email per conto dei plugin: un modulo contatti che deve inviarne una usa `mail()` sotto la propria responsabilità o un fornitore dichiarato in `domains`.

## 5. Caricamento e isolamento

- Il core legge `data/kris_plugins.json` e include `plugin.php` solo dei plugin attivi, e solo nelle richieste che li riguardano: pagine pubbliche (`head`, `body_end`, blocchi), il proprio endpoint, gli eventi. L'editor non include codice dei plugin, tranne per le azioni delle loro schermate (§6) e negli eventi.
- Ogni chiamata a un aggancio è in un `try` su `Throwable`. Un'eccezione non rompe la pagina: l'aggancio restituisce niente, l'errore va nel registro del plugin e in `kris_plugins.json` (`last_error`).
- Un errore fatale (memoria, sintassi, funzione mancante) non si cattura. Il core segna in `kris_plugins.json` il plugin in esecuzione prima di ogni aggancio; una funzione di shutdown che trova un errore fatale con quel segno spegne il plugin (`active: false`, `disabled_reason`). La richiesta successiva parte senza il plugin e l'editor mostra l'avviso «Plugin disattivato» con il motivo.
- **Modalità sicura:** `config/plugins.php` con `safe_mode` vero spegne tutti i plugin senza toccare il loro stato; l'editor lo segnala in ogni schermata. È la via d'uscita quando il sito non risponde e l'editor non si apre: si crea il file via FTP.
- I plugin non girano nell'anteprima dello staging finché la `api` 1 non lo prevede: l'anteprima mostra il tema senza plugin e lo dice nella barra. (Motivo: l'anteprima è aperta dall'admin con la sessione dell'editor; gli script dei plugin andrebbero approvati come quelli del tema.)
- Ordine: i plugin si eseguono nell'ordine di installazione. Nessuna dipendenza fra plugin nella `api` 1.

## 6. Editor

**Dichiarativo.** Il pannello di impostazioni (`#/plugin/<nome>`) è generato dai campi di `settings`, con gli stessi controlli della modifica di un contenuto; si salva in entrambe le modalità (sono dati del plugin, non contenuti in attesa). Le voci di `editor` (gruppo «Plugin installati» nella barra laterale, riquadro nella dashboard, scheda delle impostazioni) sono lette dal manifest: per mostrarle l'editor non esegue codice del plugin.

**Schermate.** Un plugin può avere schermate intere, di qualunque complessità: un modulo ES compilato (`public/editor/index.js`, più `style.css` facoltativo) che l'editor carica con `import()` quando serve. Il modulo esporta:

```js
import Ordini from './Ordini.vue'
import Riepilogo from './Riepilogo.vue'
export default { views: { ordini: Ordini, riepilogo: Riepilogo } }
```

e importa `vue` e `@kris/editor` come moduli esterni: la pagina dell'editor li risolve con una import map verso i file compilati dell'editor stesso (`kris/editor/dist/`, voci `src/vue.js` e `src/kit.js` del manifest di Vite), quindi il plugin usa la stessa istanza di Vue, di Pinia e dei componenti, senza ricaricarli. Ogni vista riceve le prop `plugin` (nome, titolo, versione) e `view`; nel riquadro della dashboard anche `compact`.

`@kris/editor` (`admin-src/src/kit.js`) è il contratto con le schermate dentro la stessa `api`: `KIcon`, `KSwitch`, `SortableList`, `MediaPicker`, `RichText`, `FieldInput`, `useDirty` (modifiche non salvate), `useSessionStore`, `useSiteStore`, `useUiStore` (`toast`, `confirm`), `upload`, `ApiError`, `pluginApi(nome)` e `KIT_API`. L'aspetto è quello del design system dell'editor: classi e variabili di `styles.css` (`box`, `table-list`, `btn`, `input`, `field`, `savebar`, i colori in `var(--…)`), che il plugin usa senza ridefinirle; il suo CSS (`style` nel manifest, meglio `scoped`) aggiunge solo quello che il kit non ha, con le stesse variabili, così chiaro e scuro funzionano da soli.

**Azioni sul server.** `pluginApi(nome).get(azione, query)` e `.post(azione, corpo)` chiamano `index.php?api=plugin-call&name=<nome>&action=<azione>` dell'editor (sessione e, per le scritture, CSRF già controllati) e restituiscono quello che restituisce l'aggancio `editor:<azione>` del plugin, dichiarato in `editor.actions`. L'aggancio riceve `method`, `query`, `body`, `user`, `mode`; una `PluginException` arriva all'editor come messaggio da mostrare, le altre eccezioni vanno nel registro.

Il modulo si compila con Vite in modalità libreria nella repository del plugin:

```js
// vite.config.mjs
export default defineConfig({
  plugins: [vue()],
  build: {
    outDir: '../plugin/public/editor',
    emptyOutDir: true,
    lib: { entry: 'src/index.js', formats: ['es'], fileName: () => 'index.js', cssFileName: 'style' },
    rollupOptions: { external: ['vue', '@kris/editor'] },
  },
})
```

I tipi di campo nuovi nel pannello delle impostazioni restano fuori dalla `api` 1: un plugin che ne ha bisogno usa una schermata propria.
## 7. Dati

- I tipi del plugin sono nello schema del sito come gli altri, con `"reserved": true` come i tipi `kris_`; è il prefisso `plg_<nome>_` a dire di quale plugin sono. Non compaiono fra le raccolte dell'editor, in Struttura né nelle operazioni dello staging: le operazioni su un tipo `plg_` sono rifiutate come quelle sui tipi `kris_`. L'agente non vede né scrive i dati dei plugin.
- Le migrazioni del plugin (`migrations/NNNN_nome.php`) hanno lo stesso contratto di quelle del core (funzione pura su `schema`, `content`, `settings`, idempotente, solo in avanti) e possono toccare solo i tipi elencati in `types`: il core confronta prima e dopo e rifiuta il risultato se cambia altro. Lo stato delle migrazioni applicate è in `kris_plugins.json`.
- Il tipo delle impostazioni (`plg_<nome>_settings`) lo crea e lo aggiorna il core dai campi del manifest, senza migrazione: campi nuovi si aggiungono vuoti, campi tolti restano nei dati finché il plugin non viene rimosso.
- Le snapshot degli aggiornamenti del core comprendono i dati dei plugin (sono nei documenti); l'aggiornamento del core non tocca `plugins/` né `assets/plugins/`.

## 8. Catalogo, versioni e installazione

### 8.1 Fonte

- Catalogo: `plugins.json` e `plugins.json.sig` su `main` di `fellarrusto/kris-cms`, letti da raw.githubusercontent.com come il canale del core. Elenca i plugin ufficiali: nome, repository, titolo, descrizione.
- Ogni plugin ha la sua repository `fellarrusto/kris-plugin-<nome>`, con `releases.json` e `releases.json.sig` su `main`: le versioni (dai tag `vX.Y.Z`), lo zip della release, sha256, `requires`, changelog. Mai l'API di GitHub.
- Il core accetta solo repository dell'account `fellarrusto` e solo zip firmati con le chiavi di `kris/update/keys.php`. Nessun caricamento di zip dall'editor e nessun catalogo alternativo da configurazione: un sito che vuole un plugin non ufficiale non ha la via per installarlo.
- Una release di plugin si pubblica dalla repository del plugin con `php <kris-cms>/tools/plugin-release.php --key … --changelog "…" --publish` (stessa chiave e stessi controlli del core: pacchetto, firma, tag, zip riscaricato e confrontato, `releases.json` per ultimo). Il catalogo si aggiorna con `--catalog --name … --title … --description …` nella repository kris-cms, poi PR su `main`.

### 8.2 Installazione

Da Impostazioni › Plugin, sul sito pubblico (non in «In attesa»), con la password di chi installa: qualunque utente dell'editor, perché l'unico potere in più dell'admin è gestire gli utenti (come per gli aggiornamenti del core). Installare e togliere chiedono la password; accendere chiede conferma, dice che il plugin esegue codice sul server con gli stessi permessi di Kris ed elenca i domini esterni e i cookie che dichiara (si leggono dal manifest del pacchetto installato, ancora spento).

1. La versione si sceglie nella scheda; predefinita l'ultima compatibile con la versione di Kris e di PHP del sito.
2. Scaricamento, verifica di firma e hash, controllo del manifest (nome uguale alla repository, `api` nota, `types` con il prefisso giusto, file pubblici ammessi), controllo di sintassi dei file PHP.
3. Con modifiche in attesa nello staging l'installazione è bloccata, come l'aggiornamento del core: le migrazioni cambiano lo schema su cui si rieseguono le operazioni.
4. Copia di sicurezza dei documenti e della cartella del plugin (se c'è una versione precedente), poi scambio di `plugins/<nome>/` e `assets/plugins/<nome>/`, migrazioni, tipo delle impostazioni, stato in `kris_plugins.json`. Un errore in uno di questi passi riporta tutto com'era.
5. Il plugin installato è spento finché l'admin non lo accende: così può prima compilare le impostazioni.

**Aggiornamento:** stessi passi; l'avviso all'apertura dell'editor vale anche per i plugin (`piano.md` §7). **Disattivazione:** il codice non si carica più, i dati restano. **Rimozione:** cancella `plugins/<nome>/` e `assets/plugins/<nome>/`; la conferma chiede se eliminare anche i tipi `plg_<nome>_*` e i loro contenuti.

## 9. Privacy

- Il registro dei cookie è l'unione dei `cookies` dei plugin attivi. Privacy e cookie lo mostra in sola lettura; `Kris\Plugin\Plugins::cookies()` lo dà al plugin della cookie policy, che lo rende come blocco.
- Le categorie dei cookie dichiarati devono esistere nel banner: un plugin con cookie `statistics` su un sito senza banner fa comparire nell'editor un avviso («Il plugin Misurazione usa cookie di statistica: serve il banner dei cookie»).
- I domini dichiarati compaiono nel riepilogo della privacy, per l'informativa che compila il cliente.

## 10. Plugin di prima parte

Banco di prova del contratto; ognuno usa una parte diversa degli agganci.

| Plugin | Usa |
| --- | --- |
| **Misurazione**: GA4 e Pixel di Meta con Consent Mode v2, eventi di contatto (clic su `tel:`, `wa.me`, link alle mappe) | `head`, `body_end`, consenso per categoria, impostazioni dichiarative, `cookies`, `domains` |
| **Cookie policy automatica**: tabella dei cookie dal registro | `block:tabella`, `Plugins::cookies()`, impostazioni |
| **IndexNow**: avvisa i motori di ricerca delle pagine pubblicate | `published`, `http()`, chiave servita da un endpoint `GET`, registro |

## 11. Fuori dalla `api` 1

Tipi di campo nuovi nell'editor, plugin nell'anteprima dello staging, dipendenze fra plugin, caricamento di file dagli endpoint, operazioni pianificate (non c'è cron sugli hosting condivisi), agganci che modificano il rendering. Ognuno entra in una `api` successiva solo con un caso d'uso concreto; una `api` nuova non rompe i plugin della precedente finché il core la supporta.

## 12. Decisioni prese con l'implementazione

- Il kit per le schermate è `@kris/editor` (§6); le schermate sono moduli Vue, non viste PHP.
- Contro lo spam dei moduli c'è il gettone e il limite di frequenza; un campo esca, se servirà, lo aggiunge il primo plugin con un modulo pubblico.
- L'errore fatale si riconosce in memoria (il plugin in esecuzione) dalla funzione di shutdown della stessa richiesta: niente scritture su disco prima di ogni aggancio.
- Installano i plugin tutti gli utenti dell'editor, con la loro password (§8.2).

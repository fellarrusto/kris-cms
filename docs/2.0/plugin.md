# Kris 2.0: plugin

Stato: specifica della fase 1, da implementare nella fase 7 (dopo il rifacimento dell'editor). Traduce in contratti le decisioni di `piano.md` §6 e i vincoli di `specifica.md` §4. Dove diverge dal piano vale questa specifica.

## 1. Cos'è un plugin

Un plugin è codice PHP che aggiunge al sito una funzione che non serve a tutti i siti e spesso nomina un fornitore: misurazione con GA4, un modulo contatti, IndexNow. Il core resta generico: se una funzione serve a qualunque sito va nel core, altrimenti diventa un plugin.

Un plugin:

- ha il suo codice fuori da `kris/`, che gli aggiornamenti sostituiscono;
- aggiunge al sito tag, script, blocchi nei template ed endpoint HTTP;
- aggiunge all'editor impostazioni, voci e schermate, costruite con i componenti dell'editor;
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
| `config/plugins.php` | Facoltativo: `return ['safe_mode' => true];` spegne tutti i plugin (modalità sicura); `'enabled' => false` toglie i plugin dall'editor. |

`plugins/` non è servita direttamente: l'`.htaccess` di Kris la nega (regola aggiunta da `Stubs`), e ogni file PHP del pacchetto si apre con `defined('KRIS_ROOT') || exit;`, così anche su Nginx senza regole un file chiamato dal browser non fa niente. In `assets/plugins/` l'installazione copia solo `.css`, `.js`, `.svg`, `.png`, `.jpg`, `.webp`, `.avif`, `.gif`, `.woff2`; niente PHP né `.htaccess`.

Gli asset del plugin ricevono `?v=` come quelli del tema (`Kris\Template\Assets`).

### 2.2 Il pacchetto

```text
plugin/
  plugin.json          manifest
  plugin.php           punto di ingresso: restituisce una funzione
  src/                 classi del plugin (namespace Kris\Plugins\<Nome>)
  views/               schermate proprie dell'editor
  templates/           frammenti HTML per i blocchi nei template
  migrations/          NNNN_nome.php, sui propri tipi
  public/              file pubblici, copiati in assets/plugins/<nome>/
  MANIFEST.json        sha256 di ogni file
  MANIFEST.sig         firma Ed25519 di MANIFEST.json
```

Lo zip contiene la sola cartella `plugin/` con `MANIFEST.json` e `MANIFEST.sig`, come il pacchetto del core contiene `kris/`. La verifica riusa `Kris\Update\Package` (limiti di dimensione, percorsi, firma, hash di ogni file) con la radice `plugin/`.

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
  "editor": {"nav": false, "settings_tab": true, "dashboard": false, "actions": []},
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
| `editor` | Le voci dell'editor (§6). Dichiarative: l'editor le mostra senza eseguire codice del plugin. |
| `endpoints` | Nomi degli endpoint HTTP (§4.4), con il limite di frequenza. |
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
| `items(string $type)` | Accesso ai contenuti dei propri tipi: `get`, `list`, `save`, `delete`, `atomic` (interfaccia `Content` di `specifica.md` §1.4). Un tipo fuori da `types` è rifiutato. |
| `read(string $type, int $id = 0)` | Lettura in sola lettura di un contenuto del sito (non `kris_`, non di altri plugin), come `{{tipo.campo}}`. |
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

L'aggancio riceve `method`, `query`, `body` (campi del modulo o JSON decodificato), `lang` e restituisce `['status' => 200, 'json' => …]` oppure `['status' => 303, 'location' => …]` (solo indirizzi del sito). Kris non manda email per conto dei plugin: un modulo contatti che deve inviarne una usa `mail()` sotto la propria responsabilità o un fornitore dichiarato in `domains`.

## 5. Caricamento e isolamento

- Il core legge `data/kris_plugins.json` e include `plugin.php` solo dei plugin attivi, e solo nelle richieste che li riguardano: pagine pubbliche (`head`, `body_end`, blocchi), il proprio endpoint, gli eventi. L'editor non include codice dei plugin, tranne nelle loro schermate (§6) e negli eventi.
- Ogni chiamata a un aggancio è in un `try` su `Throwable`. Un'eccezione non rompe la pagina: l'aggancio restituisce niente, l'errore va nel registro del plugin e in `kris_plugins.json` (`last_error`).
- Un errore fatale (memoria, sintassi, funzione mancante) non si cattura. Il core segna in `kris_plugins.json` il plugin in esecuzione prima di ogni aggancio; una funzione di shutdown che trova un errore fatale con quel segno spegne il plugin (`active: false`, `disabled_reason`). La richiesta successiva parte senza il plugin e l'editor mostra l'avviso «Plugin disattivato» con il motivo.
- **Modalità sicura:** `config/plugins.php` con `safe_mode` vero spegne tutti i plugin senza toccare il loro stato; l'editor lo segnala in ogni schermata. È la via d'uscita quando il sito non risponde e l'editor non si apre: si crea il file via FTP.
- I plugin non girano nell'anteprima dello staging finché la `api` 1 non lo prevede: l'anteprima mostra il tema senza plugin e lo dice nella barra. (Motivo: l'anteprima è aperta dall'admin con la sessione dell'editor; gli script dei plugin andrebbero approvati come quelli del tema.)
- Ordine: i plugin si eseguono nell'ordine di installazione. Nessuna dipendenza fra plugin nella `api` 1.

## 6. Editor

L'editor rifatto (task 19, `editor-specifiche.md` §6.10) offre ai plugin due livelli.

**Dichiarativo.** Il pannello di impostazioni è generato dai campi di `settings`, con gli stessi controlli della modifica di un contenuto, sezioni e barra di salvataggio. Le voci di `editor` (navigazione nel gruppo «Plugin installati», scheda nelle impostazioni, riquadro della dashboard, azione su un contenuto) sono lette dal manifest: per mostrarle l'editor non esegue codice del plugin.

**Vista propria.** Per un'interfaccia che un modulo non copre (un calendario delle prenotazioni): `views/<nome>.php`, inclusa dall'editor dentro il suo layout, con a disposizione solo i componenti documentati del kit dell'editor (partial e classi). Niente CSS proprio nell'editor: i componenti bastano o il plugin non ha quella vista. Le azioni della vista passano da `endpoint` dell'editor (`kris-admin/?plugin=<nome>&action=<x>`), che richiede sessione e CSRF dell'editor.

Il riquadro della dashboard e l'azione su un contenuto sono viste proprie piccole, con lo stesso contratto. I tipi di campo nuovi restano fuori dalla `api` 1: richiedono che il kit dell'editor abbia un contratto per i controlli, che il rifacimento deve prima definire.

## 7. Dati

- I tipi del plugin sono nello schema del sito come gli altri, con `"reserved": true` come i tipi `kris_`; è il prefisso `plg_<nome>_` a dire di quale plugin sono. Non compaiono fra le raccolte dell'editor, in Struttura né nelle operazioni dello staging: le operazioni su un tipo `plg_` sono rifiutate come quelle sui tipi `kris_`. L'agente non vede né scrive i dati dei plugin.
- Le migrazioni del plugin (`migrations/NNNN_nome.php`) hanno lo stesso contratto di quelle del core (funzione pura su `schema`, `content`, `settings`, idempotente, solo in avanti) e possono toccare solo i tipi elencati in `types`: il core confronta prima e dopo e rifiuta il risultato se cambia altro. Lo stato delle migrazioni applicate è in `kris_plugins.json`.
- Il tipo delle impostazioni (`plg_<nome>_settings`) lo crea e lo aggiorna il core dai campi del manifest, senza migrazione: campi nuovi si aggiungono vuoti, campi tolti restano nei dati finché il plugin non viene rimosso.
- Le snapshot degli aggiornamenti del core comprendono i dati dei plugin (sono nei documenti) e la cartella `plugins/`.

## 8. Catalogo, versioni e installazione

### 8.1 Fonte

- Catalogo: `plugins.json` e `plugins.json.sig` su `main` di `fellarrusto/kris-cms`, letti da raw.githubusercontent.com come il canale del core. Elenca i plugin ufficiali: nome, repository, titolo, descrizione.
- Ogni plugin ha la sua repository `fellarrusto/kris-plugin-<nome>`, con `releases.json` e `releases.json.sig` su `main`: le versioni (dai tag `vX.Y.Z`), lo zip della release, sha256, `requires`, changelog. Mai l'API di GitHub.
- Il core accetta solo repository dell'account `fellarrusto` e solo zip firmati con le chiavi di `kris/update/keys.php`. Nessun caricamento di zip dall'editor e nessun catalogo alternativo da configurazione: un sito che vuole un plugin non ufficiale non ha la via per installarlo.
- Una release di plugin si pubblica con `tools/release.php --plugin <cartella>` (stessa chiave e stessi controlli del core: pacchetto, firma, tag, zip riscaricato e confrontato, `releases.json` per ultimo).

### 8.2 Installazione

Solo l'admin, dall'editor sul sito pubblico (non in «In attesa»), con la password dell'account. La conferma dice che il plugin esegue codice sul server con gli stessi permessi di Kris ed elenca domini esterni e cookie dichiarati.

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

## 12. Da decidere con l'implementazione

- I componenti del kit dell'editor disponibili alle viste proprie: li fissa il rifacimento dell'editor (task 19).
- Se il gettone dei moduli basta contro lo spam o serve anche un campo esca: si decide con il primo plugin con un modulo.

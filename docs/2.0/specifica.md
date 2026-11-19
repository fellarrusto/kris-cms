# Kris 2.0 — specifica tecnica

Stato: bozza della fase 1. Traduce in contratti precisi le decisioni di `piano.md`. Dove le due cose divergono vale questa specifica, e il piano va corretto.

## 1. Archivio dei dati

### 1.1 Due livelli

Oggi tutto il codice lavora su documenti interi: l'editor rilegge e riscrive `k_data.json` a ogni salvataggio, staging e migrazioni sono funzioni pure su `k_data`, `k_model`, `cms_settings`, pagine e SEO. Questa forma funziona e si tiene. Il database però serve proprio per non riscrivere tutto a ogni modifica. Per questo l'archivio ha due livelli:

| Livello | Chi lo usa | Unità |
| --- | --- | --- |
| **Documenti** (`Kris\Store\Store`) | Staging, migrazioni, installer, esportazione | Un documento intero: schema, contenuti, impostazioni |
| **Contenuti** (`Kris\Store\Content`) | Sito pubblico, editor, agente, plugin | Un contenuto, con revisione |

Entrambi i livelli sono implementati da ogni adapter. Chi scrive codice nuovo usa i contenuti; i documenti restano per chi deve vedere o sostituire lo stato intero.

### 1.2 Documenti

| Documento | Contiene | Oggi |
| --- | --- | --- |
| `schema` | Tipi, pagine, reindirizzamenti | `k_model.json`, parte di `cms_settings.json`, `allowed_pages.json`, `seo.json` |
| `content` | Tutti i contenuti, nell'ordine | `k_data.json` |
| `settings` | Lingue, caricamenti, opzioni del sito non editoriali | resto di `cms_settings.json` |

Lo stato del framework (`kris_state.json`), lo staging, i backup, i nonce dell'agente e i file di `config/` restano file: non sono contenuti del sito e servono anche quando il database non risponde.

### 1.3 Interfaccia `Store`

```php
namespace Kris\Store;

interface Store
{
    /** Il documento e la sua revisione. Un documento che non esiste è [] con revisione ''. */
    public function read(string $doc): Snapshot;

    /**
     * Sostituisce il documento. Con $expectedRev la scrittura riesce solo se
     * nessuno ha scritto dopo quella revisione, altrimenti ConflictException.
     * Restituisce la revisione nuova.
     */
    public function write(string $doc, array $value, ?string $expectedRev = null): string;

    /**
     * Più documenti tutti o nessuno: lo usano pubblicazione dello staging,
     * migrazioni e importazione. La callback riceve i documenti letti sotto
     * lock e restituisce quelli da scrivere.
     *
     * @param list<string> $docs
     * @param callable(array<string, array>): array<string, array> $change
     */
    public function transaction(array $docs, callable $change): void;

    /** Tutti i documenti, per snapshot degli aggiornamenti e passaggio fra adapter. */
    public function export(): array;

    public function import(array $documents): void;

    public function content(): Content;
}
```

`Snapshot` è un oggetto con `value` (array) e `rev` (stringa opaca). La revisione dell'adapter JSON è lo sha256 del file; quella del database un intero incrementato a ogni scrittura.

### 1.4 Interfaccia `Content`

```php
interface Content
{
    public function get(string $type, int $id): ?Item;

    /** I contenuti di un tipo nell'ordine; $where filtra su valori condivisi fra le lingue. */
    public function list(string $type, array $where = [], ?int $limit = null, int $offset = 0): array;

    public function findBySlug(string $type, string $slug, string $lang): ?Item;

    /** Crea (id null) o aggiorna. Con $item->rev non nulla, ConflictException se è cambiato nel frattempo. */
    public function save(Item $item): Item;

    public function delete(string $type, int $id, ?int $expectedRev = null): void;

    /** @param list<int> $ids il nuovo ordine dei contenuti del tipo */
    public function reorder(string $type, array $ids): void;

    /** Più scritture tutte o nessuna, sotto lock: per i plugin (prenotazioni, contatori). */
    public function atomic(callable $work): mixed;
}
```

`Item`: `type`, `id`, `rev` (intero), `hidden`, `slug` (per lingua, facoltativo), `slugHistory`, `values` (nome del campo → valore), `updatedAt`.

### 1.5 Adapter

**JSON** (predefinito). Un file per documento in `data/`: `schema.json`, `content.json`, `settings.json`. `Content` lavora sul documento `content` in memoria e lo riscrive intero sotto `flock`: semplice e corretto, lento solo con molte scritture concorrenti. Scrittura atomica con file temporaneo e copia precedente in `data/backups/`, come `JsonStore` oggi.

**Database** (PDO: SQLite e MySQL/MariaDB).

```sql
kris_documents (name VARCHAR(64) PRIMARY KEY, rev BIGINT NOT NULL, body LONGTEXT NOT NULL)
kris_items (
  type VARCHAR(64) NOT NULL, id INT NOT NULL, rev INT NOT NULL,
  position INT NOT NULL, hidden TINYINT NOT NULL DEFAULT 0,
  slug_json TEXT, slug_history_json TEXT, values_json LONGTEXT NOT NULL,
  updated_at VARCHAR(32) NOT NULL,
  PRIMARY KEY (type, id), INDEX (type, position)
)
kris_slugs (type VARCHAR(64), lang VARCHAR(8), slug VARCHAR(190), id INT, current TINYINT,
  PRIMARY KEY (type, lang, slug))
```

**Implementato in 2.0 (fase 5):** solo `kris_documents`, con tutti i documenti (`content` compreso) uno per riga; revisione intera, scritture serializzate (`BEGIN IMMEDIATE` su SQLite, `GET_LOCK` su MySQL). `kris_items` e `kris_slugs` arrivano con il livello `Content`, quando servirà scrivere un contenuto senza riscrivere il documento. Il testo che segue descrive quel passo.

I documenti `schema` e `settings` stanno in `kris_documents`. Il documento `content` non è salvato come documento: `read('content')` lo compone da `kris_items`, `write('content')` lo scompone in righe nella stessa transazione. Così staging e migrazioni funzionano identici sui due adapter.

Prefisso delle tabelle configurabile (`kris_` predefinito), per database condivisi.

**Scelta dell'adapter**: `config/store.php`, privato ed escluso da Git:

```php
return ['driver' => 'json'];
return ['driver' => 'pdo', 'dsn' => 'mysql:host=localhost;dbname=sito;charset=utf8mb4', 'user' => '…', 'password' => '…', 'prefix' => 'kris_'];
```

Senza file vale `json`. Il passaggio da un adapter all'altro è `tools/store.php convert --to pdo`: `export()` da uno, `import()` nell'altro, verifica, poi riscrive `config/store.php`.

### 1.6 Regole

- Nessun codice fuori da `Kris\Store` apre i file di `data/` dei contenuti o fa query SQL.
- Le eccezioni sono `Kris\Store\ConflictException` (revisione superata) e `Kris\Store\StoreException` (archivio illeggibile o non scrivibile). L'editor mostra la prima come conflitto da risolvere, la seconda come errore dei dati.
- Le revisioni sono opache: si confrontano, non si calcolano.
- L'anteprima dello staging oggi sovrappone file (`Paths::useOverlay`). Con l'archivio diventa uno `Store` in sola lettura costruito dallo stato dello staging (`OverlayStore`): il rendering lo riceve al posto di quello del sito.

## 2. Formato 2

Implementato sul ramo `2.0-formato-2` (migrazione `0009_formato_2.php`). Questa sezione descrive il formato com'è; le differenze dalla bozza sono in fondo (2.7).

### 2.1 Documento `schema`

```json
{
  "format": 2,
  "types": {
    "progetti": {
      "label": "Progetti",
      "description": "I lavori in portfolio",
      "single": false,
      "newest_first": false,
      "fields": [
        {"name": "title", "type": "text", "localized": true, "description": "Titolo", "section": "Intestazione"},
        {"name": "category", "type": "select", "localized": false, "options": ["web", "app"], "multiple": false},
        {"name": "featured", "type": "boolean", "localized": false},
        {"name": "cover", "type": "media", "localized": true},
        {"name": "body", "type": "richtext", "localized": true},
        {"name": "gallery", "type": "list", "of": [{"name": "photo", "type": "media", "localized": true}]}
      ]
    }
  },
  "pages": {
    "homepage": {"template": "homepage", "type": "homepage", "item": 0, "sitemap": true},
    "progetto": {"template": "progetto", "type": "progetti", "sitemap": true, "priority": 0.8, "slug": "url"},
    "scatto": {"template": "scatto", "type": "progetti", "item": 0, "list": "gallery", "sitemap": false}
  },
  "promoted": [{"from": "homepage", "id": 0, "list": "posts", "to": "posts"}],
  "redirects": {"/vecchio-menu": "/menu"}
}
```

- `types`: chiavi in `snake_case`, nell'ordine dell'editor (la posizione è l'ordine). `label`, `description`, `single` (contenuto unico), `newest_first` (Posts: i nuovi in cima) ci sono solo se servono. I tipi `kris_*` hanno `"reserved": true`: il loro schema lo scrivono solo le migrazioni.
- `pages`: chiave = nome della pagina e del suo indirizzo. `type` è il tipo che rende. Con `item` rende sempre quel contenuto; senza `item` un contenuto del tipo per id o per slug; con `item` e `list` un elemento dell'elenco. `sitemap` sì o no, `priority` (0–1), `slug` (il campo da cui viene l'indirizzo leggibile).
- `promoted`: gli elenchi della 1.x diventati tipi alla conversione. I vecchi riferimenti `key=from&id=id&path=list/N` valgono il contenuto `N` del tipo `to`: rendering, link riscritti dal Router e contesti dei template li seguono.
- `redirects`: 301, percorsi dalla cartella del sito o indirizzi https.

### 2.2 Tipi di campo

| Tipo | Valore condiviso (`localized: false`) | Valore per lingua (`localized: true`) | Uscita nel template |
| --- | --- | --- | --- |
| `text` | `"stringa"` | `{"it": "…", "en": "…"}` | escape HTML |
| `richtext` | come text | come text | HTML filtrato (2.5) |
| `media` | `"assets/uploads/x.webp"` | per lingua | escape; `srcset` sugli `<img>` |
| `select` | `"web"`, o `["web", "app"]` con `multiple` | per lingua | il valore; una lista separata da virgole (per `has`) |
| `boolean` | `true` / `false` | per lingua, senza ripiego su un'altra lingua | `"1"` o `""`; nelle condizioni vero o falso |
| `list` | elementi `{"id", "hidden"?, "values"}` | — | con `k-array` |

`localized` non si applica a `list`: i suoi campi decidono ciascuno. Le operazioni dello staging accettano anche i nomi della 1.x (`plain` = text condiviso, `image` = media, `array` = list).

### 2.3 Documento `content`

Una lista nell'ordine, con i valori per nome:

```json
[
  {"type": "progetti", "id": 3, "hidden": true,
   "values": {"title": {"it": "Casa Rossi", "en": "Rossi House"}, "featured": true,
              "gallery": [{"id": 0, "values": {"photo": {"it": "assets/uploads/casa.webp", "en": "assets/uploads/casa.webp"}}}]}}
]
```

Il tipo del campo sta solo nello schema. Un valore il cui campo non esiste più nello schema resta nel documento e non si rende: lo elimina solo `remove_field`.

### 2.4 Documento `settings`

```json
{"languages": ["it", "en"], "uploads": {"prefix": "", "max_width": 2560, "webp": true},
 "seo": {"base_url": "https://www.esempio.it", "head": true, "pretty_urls": true, "auto_language": false, "robots": "Disallow: /bozze/"}}
```

La prima lingua è la principale.

### 2.5 Richtext

`Kris\Template\Richtext`, al salvataggio (editor, staging, agente) e al rendering: restano `p br strong b em i u s a ul ol li h2 h3 h4 h5 h6 blockquote code pre span img figure figcaption table thead tbody tr th td hr`. Attributi: `class` e `title` ovunque, `href` (solo `http`, `https`, `mailto`, `tel`, relativi e `#`), `target` (`_blank` aggiunge `rel="noopener"`), `rel`, `src` e `alt` di `img` (solo indirizzi del sito o `https`), `colspan`, `rowspan`. Un elemento non ammesso lascia il suo contenuto; script, stili, iframe e simili spariscono con il loro contenuto. Un tema che ha bisogno di HTML libero (un embed) usa un campo `text` e lo inserisce nel template, dove lo controlla lo sviluppatore.

### 2.6 Migrazione dal formato 1

Una sola migrazione, `0009_formato_2.php`, funzione pura: riceve i documenti della 1.x (`k_data`, `k_model`, `cms_settings`, `allowed_pages`, `seo`) e i template in lettura, restituisce `schema`, `content`, `settings`. Confermato da Filippo il 2026-11-18: elenchi con dettaglio promossi a tipo, filtro del richtext, `media` per lingua come oggi.

| Da | A |
| --- | --- |
| `k_model.json[tipo]` | `schema.types[tipo].fields` |
| `plain` | `text` con `localized: false` |
| `text`, `richtext` | stesso tipo, `localized: true` |
| `image` | `media`, `localized: true` |
| `boolean`, `select` | stesso tipo, `localized` com'era; `"1"`/`""` → `true`/`false`, `"a,b"` → `["a", "b"]` |
| `array` | `list` |
| `cms_settings.collections`, `collection_order`, `post_collections` | `label`, `description`, `single`, ordine dei tipi, `newest_first` |
| `allowed_pages.json` + regole della sitemap | `schema.pages`: tipo, `item`, `list` dalla regola; senza regola dai link dei template (`page=…&key=…`), altrimenti il tipo con il nome della pagina o la homepage, `item` 0 |
| `seo.json`: `redirects` / `base_url`, `head`, `pretty_urls`, `auto_language`, `robots` | `schema.redirects` / `settings.seo` |
| `k_data.json` | `content`, valori per nome |

**Elenchi promossi a tipo.** Un elenco di primo livello `L` del tipo `T` diventa il tipo `L` (o `T_L` se `L` esiste già) quando una regola della sitemap ha `children: L`, un template contiene link con `path=L/`, o l'elenco era in Posts (`posts: true`, che diventa `newest_first`). Solo se `T` ha un solo contenuto: con più contenuti l'elenco resta un elenco, con la sua pagina (`item` e `list`). Gli elementi diventano contenuti con lo stesso id, subito dopo il contenuto da cui vengono; `k-array="L"` continua a funzionare perché rende il tipo omonimo; `promoted` ricorda la provenienza per i vecchi link.

La migrazione è `auto: false`: l'editor la propone (e l'installer la applica con l'aggiornamento, dopo lo snapshot). Una migrazione da chiedere che sui dati del sito non cambierebbe niente si applica da sola: un sito nuovo, già nel formato 2, non se la vede chiedere. Dopo la conversione il Migrator toglie dall'archivio i documenti della 1.x e ne lascia una copia in `data/format1/`. Finché un sito non è convertito (per esempio aggiornato via FTP), `Kris\Store\Site` lo legge convertito al volo: il sito pubblico resta visibile.

### 2.7 Differenze dalla bozza

- **Rimandati al livello `Content`** (1.4), che oggi non c'è: `rev` e `updated_at` dei contenuti, `slug` e `slug_history` salvati nel contenuto. L'indirizzo leggibile viene ancora da un campo indicato dalla pagina (`slug`), come nella 1.x.
- **Opzioni delle scelte**: restano stringhe (`["web", "app"]`), senza etichette per lingua.
- **Pagine**: niente `path` personalizzato; l'indirizzo è il nome della pagina (`/progetto/3`), come nella 1.x. `noindex` non c'è.
- **Editor**: lavora direttamente sul formato 2 (`kris/editor/model.php`); ogni scrittura è un'operazione, applicata subito da `Kris\Staging\Live` o registrata nello staging. Restano da fare le schermate nuove del canvas: Indirizzi, Elenchi di valori, dettaglio di un file della libreria, Plugin.

## 3. Indirizzi

### 3.1 Risoluzione

Ordine, per una richiesta a `/<percorso>`:

1. File esistente (asset, upload, stub): lo serve il web server.
2. `kris-admin/…`: l'editor.
3. Prefisso di lingua: se il primo segmento è una lingua attiva diversa dalla principale, si toglie e si fissa la lingua.
4. `schema.redirects` (esatti): 301.
5. Pagine con percorso fisso, poi pagine con `{item}`: per `{item}` numerico si cerca l'id (301 allo slug se esiste), altrimenti lo slug corrente nella lingua, poi lo storico degli slug (301 all'attuale).
6. Vecchi indirizzi `index.php?page=…&key=…&id=…&path=…`: 301 al percorso nuovo.
7. 404.

Senza riscrittura sul server restano validi `index.php?page=…` e `index.php?p=/percorso`.

### 3.2 Generazione

`Kris\Site\Router::url(string $page, ?int $item, string $lang): string` è l'unico punto che costruisce indirizzi: lo usano `{{link}}`, canonical, hreflang, sitemap, anteprima ed editor.

### 3.3 Riscrittura dei percorsi del tema

Dopo il rendering, `href`, `src`, `srcset`, `poster`, `action` e gli `url()` negli attributi `style` che iniziano con `assets/`, `kris/`, `index.php` o `./` ricevono la base del sito (`{{base_path}}`). Non si toccano indirizzi assoluti, con schema, che iniziano con `/` o `#`.

## 4. Plugin

Il contratto è in `plugin.md` (manifest, agganci, isolamento, editor, dati, catalogo e installazione). Vincoli che valgono anche fuori da lì:

- un plugin dichiara i suoi tipi privati `plg_<nome>_*` nello schema e li usa con `Content`;
- non apre file di `data/` né il database direttamente;
- i suoi pannelli usano solo i componenti dell'editor.

## 5. Passaggio dalla 1.x

- **1.10**: legge le release `breaking` con `min_from` uguale alla versione installata e mostra il controllo di compatibilità: versione di PHP (serve 8.3), riscrittura degli indirizzi disponibile, elenchi da promuovere, pagine senza tipo ricavabile, template con link scritti a mano, `.htaccess` personalizzato. Se tutto è risolvibile in automatico propone l'installazione.
- **2.0.0**: l'installer converte con `0009_formato_2.php` dopo lo snapshot dei file attuali; i file del formato 1 restano in `data/format1/`.

## 6. Ordine di lavoro

1. **Fase 2a**: `Kris\Store` con adapter JSON sui file del formato 1 (`k_data`, `k_model`, `cms_settings`, pagine, SEO). Tutto il codice passa dall'archivio; test invariati.
2. **Fase 2b**: `OverlayStore` per l'anteprima al posto di `Paths::useOverlay` per i dati.
3. **Fase 3**: formato 2 dietro la stessa interfaccia, migrazione `0009`, tipi di campo nuovi.
4. **Fase 5**: adapter PDO, verificato con gli stessi test dell'adapter JSON (suite comune eseguita su entrambi).

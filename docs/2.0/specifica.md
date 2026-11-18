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
      "seo": true,
      "position": 3,
      "fields": [
        {"name": "title", "type": "text", "localized": true, "label": "Titolo", "description": "…", "section": "Intestazione"},
        {"name": "category", "type": "select", "localized": false, "multiple": false,
         "options": [{"value": "web", "label": {"it": "Web", "en": "Web"}}]},
        {"name": "featured", "type": "boolean", "localized": false},
        {"name": "cover", "type": "media", "localized": false},
        {"name": "body", "type": "richtext", "localized": true},
        {"name": "gallery", "type": "list", "of": [{"name": "photo", "type": "media", "localized": false}]}
      ]
    }
  },
  "pages": {
    "homepage": {"template": "homepage", "type": "homepage", "path": "/", "sitemap": true, "noindex": false, "priority": 1.0},
    "progetto": {"template": "progetto", "type": "progetti", "path": "/progetti/{item}", "sitemap": true, "noindex": false, "slug_from": "title"}
  },
  "redirects": [
    {"from": "/vecchio-menu", "to": "/menu", "auto": false}
  ],
  "auto_language": false,
  "main_language": "it"
}
```

- `types`: chiavi in `snake_case`. `single`: contenuto unico. `newest_first`: elenco di articoli, i nuovi in testa. `seo`: gruppo «motori di ricerca e condivisione» nell'editor.
- Tipi riservati `kris_*`: stessi campi, più `"reserved": true`. Lo schema lo scrivono solo le migrazioni; `kris_wip` è l'unico con campi aggiunti dal tema, con `enabled` del framework non rimovibile.
- `pages`: chiave = nome della pagina. `path` per un contenuto unico è fisso; per una raccolta contiene `{item}`, che vale lo slug o l'id. `type` è obbligatorio: una pagina rende sempre un tipo. `slug_from`: il campo da cui l'editor propone lo slug.
- `main_language` sostituisce «la prima di `languages`».

### 2.2 Tipi di campo

| Tipo | Valore non localizzato | Valore localizzato | Uscita nel template |
| --- | --- | --- | --- |
| `text` | `"stringa"` | `{"it": "…", "en": "…"}` | escape HTML |
| `richtext` | come text | come text | HTML filtrato (vedi 2.5) |
| `media` | `"assets/uploads/x.webp"` | per lingua | escape; con varianti, `srcset` sugli `<img>` |
| `select` | `"web"` o `["web", "app"]` con `multiple` | per lingua | il valore; `{{campo.label}}` l'etichetta nella lingua |
| `boolean` | `true` / `false` | per lingua | `"1"` o `""`; nelle condizioni vero o falso |
| `list` | lista di elementi `{"id", "hidden"?, "values"}` | — (sempre condivisa) | con `k-array` |

`localized` non si applica a `list`: i suoi campi decidono ciascuno.

### 2.3 Documento `content`

Una lista nell'ordine, come oggi, con valori per nome invece della lista di oggetti `{name, type, value}`:

```json
[
  {"type": "progetti", "id": 3, "rev": 7, "hidden": false,
   "slug": {"it": "casa-rossi", "en": "rossi-house"}, "slug_history": {"it": ["casa-rossi-2025"]},
   "updated_at": "2026-11-18T10:00:00Z",
   "values": {"title": {"it": "Casa Rossi", "en": "Rossi House"}, "featured": true,
              "gallery": [{"id": 0, "values": {"photo": "assets/uploads/casa.webp"}}]}}
]
```

Il tipo del campo sta solo nello schema. Un valore il cui campo non esiste più nello schema resta nel documento e non si rende (oggi si perde al salvataggio successivo): lo elimina solo un'operazione esplicita.

### 2.4 Documento `settings`

```json
{"languages": ["it", "en"], "uploads": {"prefix": "", "max_width": 2560, "webp": true, "variants": [480, 960, 1600]}}
```

### 2.5 Richtext

Al salvataggio (editor, staging, agente) e al rendering il richtext passa da un filtro con elenco di elementi ammessi: `p br strong b em i u s a ul ol li h2 h3 h4 h5 h6 blockquote code pre span img figure figcaption table thead tbody tr th td hr`. Attributi: `href` (solo `http`, `https`, `mailto`, `tel`, relativi e `#`), `title`, `target`, `rel`, `src` e `alt` di `img` (solo indirizzi del sito o `https`), `class`, `colspan`, `rowspan`. Tutto il resto si toglie. Un tema che ha bisogno di HTML libero (un embed) usa un campo `text` e lo inserisce nel template, dove lo controlla lo sviluppatore.

### 2.6 Migrazione dal formato 1

Una sola migrazione, `0007_formato_2.php`, funzione pura sui file attuali → documenti nuovi:

| Da | A |
| --- | --- |
| `k_model.json[tipo]` | `schema.types[tipo].fields` |
| `plain` | `text` con `localized: false` |
| `text`, `richtext` | stesso tipo, `localized: true` |
| `image` | `media`, `localized: true` (oggi è per lingua) |
| `array` | `list` |
| `posts: true` su un elenco di primo livello | l'elenco diventa un tipo (vedi sotto) |
| `cms_settings.collections`, `collection_order`, `post_collections` | `label`, `description`, `single`, `position`, `newest_first` del tipo |
| `allowed_pages.json` + `seo.json` | `schema.pages`; `type` dalle regole della sitemap e dai link dei template (`TemplateUsage`), altrimenti dal nome; `path` dal nome della pagina |
| `k_data.json` | `content`, valori per nome, `rev: 1` |

**Elenchi con pagina di dettaglio promossi a tipo.** Un elenco `L` del tipo `T` diventa un tipo `L` (o `T_L` se `L` esiste già) quando una regola della sitemap ha `children: L` o un template contiene link con `path=L/`. Gli elementi diventano contenuti con lo stesso id; nel contenuto di `T` il campo `L` sparisce e `k-array="L"` continua a funzionare perché cerca la raccolta omonima. La mappa `vecchio path → nuovo id` va in `schema.redirects` per i 301.

La migrazione è `auto: false`: l'editor la propone con il controllo di compatibilità della 1.10 (sezione 5), dopo lo snapshot.

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

Contratto rimandato a una specifica separata, `plugin.md`, da scrivere prima della fase 7. Vincoli già fissati:

- un plugin dichiara i suoi tipi privati `plg_<nome>_*` nello schema e li usa con `Content`;
- non apre file di `data/` né il database direttamente;
- i suoi pannelli usano solo i componenti dell'editor.

## 5. Passaggio dalla 1.x

- **1.10**: legge le release `breaking` con `min_from` uguale alla versione installata e mostra il controllo di compatibilità: versione di PHP (serve 8.3), riscrittura degli indirizzi disponibile, elenchi da promuovere, pagine senza tipo ricavabile, template con link scritti a mano, `.htaccess` personalizzato. Se tutto è risolvibile in automatico propone l'installazione.
- **2.0.0**: l'installer converte con `0007_formato_2.php` dopo lo snapshot dei file attuali; i file del formato 1 restano in `data/format1/` per 14 giorni.

## 6. Ordine di lavoro

1. **Fase 2a**: `Kris\Store` con adapter JSON sui file del formato 1 (`k_data`, `k_model`, `cms_settings`, pagine, SEO). Tutto il codice passa dall'archivio; test invariati.
2. **Fase 2b**: `OverlayStore` per l'anteprima al posto di `Paths::useOverlay` per i dati.
3. **Fase 3**: formato 2 dietro la stessa interfaccia, migrazione `0007`, tipi di campo nuovi.
4. **Fase 5**: adapter PDO, verificato con gli stessi test dell'adapter JSON (suite comune eseguita su entrambi).

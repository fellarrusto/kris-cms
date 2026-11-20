<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Operazioni su contenuti, schema e pagine, applicate a uno stato in memoria
 * nel formato 2 (docs/2.0/specifica.md, 2):
 *
 *   ['schema' => tipi, pagine, elenchi promossi, 'content' => contenuti,
 *    'settings' => lingue e opzioni]
 *
 * Funzioni pure: nessun accesso al filesystem, cosi la stessa operazione si
 * applica all'anteprima quando viene registrata e ai dati veri del sito
 * quando lo staging viene pubblicato, magari giorni dopo.
 *
 * Un'operazione registra quello che ha trovato ('before'): se alla
 * ripetizione il sito non e piu cosi (il cliente ha cambiato lo stesso campo
 * dall'editor, o ha tolto l'elemento) e un conflitto.
 *
 * Percorsi: 'key' (il tipo) e 'id' individuano il contenuto, 'path' alterna
 * nome di un elenco e id dell'elemento, come il parametro path delle pagine:
 * ['servizi', 2, 'punti', 0].
 *
 * Tipi di campo: text, richtext, media, select, boolean, list, con
 * "localized" (un valore per lingua) tranne list. I nomi della 1.x restano
 * validi: plain è un text non localizzato, image un media, array un list.
 */
final class Operations
{
    public const TYPES = ['text', 'richtext', 'media', 'select', 'boolean', 'list'];
    private const ALIASES = ['plain' => 'text', 'image' => 'media', 'array' => 'list'];

    // Gli stessi nomi che l'editor riserva ai propri controlli, piu quelli esposti dal motore dei template
    private const RESERVED_NAMES = ['id', 'language', 'group', 'path', 'save_entity', 'create_instance',
        'create_nested', 'delete_nested', 'delete_instance', 'reorder_root', 'reorder_nested',
        'save_structure', 'delete_collection', 'save_settings', 'delete_media', 'file_name',
        'order', 'group_name', 'schema_json', 'collection_name', 'langs', 'f', 'return_posts'];

    // Chiavi ammesse da chi crea contenuti: una chiave sbagliata ("data" al posto di "fields") creerebbe un elemento vuoto senza errori
    private const KEYS = [
        'add_item' => ['op', 'key', 'id', 'path', 'array', 'fields', 'position'],
        'create_entity' => ['op', 'key', 'fields'],
        'register_page' => ['op', 'page', 'sitemap'],
        'set_sitemap' => ['op', 'page', 'sitemap'],
        'set_page' => ['op', 'page', 'template', 'type', 'item', 'sitemap', 'priority', 'slug'],
    ];

    /** Le sezioni riservate di Kris di cui si scrivono i valori (non lo schema). */
    private const WRITABLE_RESERVED = ['kris_site', 'kris_legal', 'kris_notfound', 'kris_wip'];

    /**
     * Applica un'operazione. Alla prima applicazione (non $replay) completa
     * l'operazione con quanto trovato, da confrontare alla ripetizione.
     *
     * @param callable(string): bool $uploadExists un URL assets/uploads/... esiste (nel sito o nello staging)
     * @param ?\ArrayObject $warnings raccoglie gli avvisi per chi ha chiesto l'operazione (lingue rimaste vuote)
     * @return array{0: array, 1: array} stato nuovo e operazione completata
     * @throws StagingException operazione non valida, o conflitto alla ripetizione
     */
    public static function apply(array $state, array $op, array $languages, callable $uploadExists, bool $replay = false, bool $force = false, ?\ArrayObject $warnings = null): array
    {
        $type = (string) ($op['op'] ?? '');
        $method = [
            'set_field' => 'setField', 'add_item' => 'addItem', 'remove_item' => 'removeItem',
            'move_item' => 'moveItem', 'create_entity' => 'createEntity',
            'remove_entity' => 'removeEntity', 'move_entity' => 'moveEntity', 'set_hidden' => 'setHidden',
            'add_field' => 'addField', 'remove_field' => 'removeField', 'rename_field' => 'renameField',
            'describe_field' => 'describeField', 'create_collection' => 'createCollection',
            'register_page' => 'registerPage',
            'remove_collection' => 'removeCollection', 'rename_collection' => 'renameCollection',
            'change_type' => 'changeType', 'move_field' => 'moveField', 'set_options' => 'setOptions', 'set_posts' => 'setPosts',
            'set_post_collection' => 'setPostCollection', 'unregister_page' => 'unregisterPage',
            'set_collection_info' => 'setCollectionInfo', 'move_collection' => 'moveCollection',
            'set_sitemap' => 'setSitemap', 'set_page' => 'setPage',
        ][$type] ?? null;
        if ($method === null) {
            throw new StagingException("Operazione sconosciuta: '{$type}'.");
        }
        // I dati dei plugin si cambiano solo dal plugin (docs/2.0/plugin.md, 7)
        foreach (['key', 'collection', 'name', 'to'] as $k) {
            if (is_string($op[$k] ?? null) && str_starts_with($op[$k], 'plg_') && ($k !== 'name' || in_array($type, ['create_collection', 'rename_collection', 'remove_collection'], true))) {
                throw new StagingException("La raccolta «{$op[$k]}» appartiene a un plugin e non si modifica.");
            }
        }
        if (!$replay && isset(self::KEYS[$type])) {
            $unknown = array_diff(array_map('strval', array_keys($op)), self::KEYS[$type]);
            if ($unknown) {
                throw new StagingException("{$type} non conosce " . implode(', ', array_map(fn($k) => "«{$k}»", $unknown))
                    . '. Chiavi ammesse: ' . implode(', ', self::KEYS[$type]) . '.');
            }
        }
        $state += ['schema' => [], 'content' => [], 'settings' => []];
        $state['schema'] += ['types' => [], 'pages' => []];
        $ctx = ['languages' => array_values($languages), 'uploadExists' => $uploadExists, 'replay' => $replay, 'force' => $force,
            'warnings' => $warnings ?? new \ArrayObject()];
        return self::$method($state, $op, $ctx);
    }

    /** Descrizione leggibile di un'operazione, per il riepilogo nell'editor. */
    public static function describe(array $op): string
    {
        $where = self::where($op);
        return match ($op['op'] ?? '') {
            'set_field' => "Modifica «{$op['field']}»" . (isset($op['lang']) ? " ({$op['lang']})" : '') . " in {$where}",
            'add_item' => "Nuovo elemento in «{$op['array']}» di {$where}",
            'remove_item' => "Elimina l'elemento {$op['item_id']} da «{$op['array']}» di {$where}",
            'move_item' => "Sposta l'elemento {$op['item_id']} di «{$op['array']}» in posizione " . ((int) $op['to'] + 1) . " in {$where}",
            'create_entity' => "Nuovo contenuto in «{$op['key']}»",
            'remove_entity' => "Elimina {$where}",
            'move_entity' => "Sposta {$where} in posizione " . ((int) ($op['to'] ?? 0) + 1),
            'set_hidden' => (!empty($op['hidden']) ? 'Sospende ' : 'Rende visibile ') . $where,
            'add_field' => "Nuovo campo «{$op['field']['name']}» ({$op['field']['type']}) in " . self::schemaWhere($op),
            'remove_field' => "Elimina il campo «{$op['name']}» da " . self::schemaWhere($op) . ', con i suoi valori',
            'rename_field' => "Rinomina il campo «{$op['from']}» in «{$op['to']}» in " . self::schemaWhere($op),
            'describe_field' => (array_key_exists('description', $op) ? 'Nuova descrizione' : 'Nuova sezione') . " del campo «{$op['name']}» in " . self::schemaWhere($op),
            'create_collection' => "Nuova raccolta «{$op['name']}»",
            'register_page' => "Nuova pagina pubblica «{$op['page']}»" . (!array_key_exists('sitemap', $op) ? '' : ($op['sitemap'] === false || $op['sitemap'] === null ? ', fuori dalla sitemap' : ', aggiunta alla sitemap')),
            'set_options' => "Nuove opzioni del campo «{$op['name']}» in " . self::schemaWhere($op),
            'set_sitemap' => ($op['sitemap'] ?? null) === false || ($op['sitemap'] ?? null) === null
                ? "Toglie la pagina «{$op['page']}» dalla sitemap" : "Mette la pagina «{$op['page']}» nella sitemap",
            'remove_collection' => "Elimina la raccolta «{$op['name']}», con tutti i suoi contenuti e le sue pagine",
            'rename_collection' => "Rinomina la raccolta «{$op['from']}» in «{$op['to']}»",
            'change_type' => "Cambia il tipo del campo «{$op['name']}» in {$op['type']}" . (isset($op['localized']) ? ($op['localized'] ? ' per lingua' : ' condiviso') : '') . ' in ' . self::schemaWhere($op),
            'move_field' => "Sposta il campo «{$op['name']}» in posizione " . ((int) ($op['to'] ?? 0) + 1) . ' in ' . self::schemaWhere($op),
            'set_posts' => (!empty($op['posts']) ? 'Mostra in Posts' : 'Toglie da Posts') . " l'elenco «{$op['name']}» di «{$op['collection']}»",
            'set_post_collection' => (!empty($op['enabled']) ? 'Mostra in Posts' : 'Toglie da Posts') . " la raccolta «{$op['collection']}»",
            'unregister_page' => "Toglie la pagina pubblica «{$op['page']}», anche dalla sitemap",
            'set_page' => "Modifica la pagina pubblica «{$op['page']}»",
            'set_collection_info' => "Nome, descrizione o contenuto unico della raccolta «{$op['collection']}»",
            'move_collection' => "Sposta la raccolta «{$op['collection']}» in posizione " . ((int) ($op['to'] ?? 0) + 1),
            default => (string) ($op['op'] ?? 'operazione'),
        };
    }

    /** Raccolte tolte o rinominate: i template che le usano ancora vanno aggiornati. */
    public static function retiredCollections(array $op): array
    {
        return match ($op['op'] ?? '') {
            'remove_collection' => [(string) $op['name']],
            'rename_collection' => [(string) $op['from']],
            default => [],
        };
    }

    /**
     * I testi richtext che un'operazione scrive (set_field, add_item,
     * create_entity): sono HTML che il sito emette, quindi codice da far
     * approvare come quello dei file. $schema è lo schema in cui
     * l'operazione si applica.
     *
     * @return list<string>
     */
    public static function richtextValues(array $schema, array $op): array
    {
        try {
            $key = (string) ($op['key'] ?? '');
            switch ($op['op'] ?? '') {
                case 'set_field':
                    $def = self::fieldDef(self::schemaAt($schema, $key, self::listNames(self::path($op))), (string) ($op['field'] ?? ''));
                    return $def['type'] === 'richtext' ? self::strings($op['value'] ?? null) : [];
                case 'add_item':
                    $names = array_merge(self::listNames(self::path($op)), [(string) ($op['array'] ?? '')]);
                    return self::richtextIn(self::schemaAt($schema, $key, $names), (array) ($op['fields'] ?? []));
                case 'create_entity':
                    return self::richtextIn(self::schemaAt($schema, $key, []), (array) ($op['fields'] ?? []));
            }
        } catch (StagingException $e) {
            // Un'operazione che non si applica più non scrive nulla
        }
        return [];
    }

    private static function richtextIn(array $fields, array $values): array
    {
        $found = [];
        foreach ($fields as $def) {
            if (!is_array($def) || !array_key_exists($def['name'] ?? '', $values)) continue;
            $value = $values[$def['name']];
            if (($def['type'] ?? null) === 'richtext') {
                array_push($found, ...self::strings($value));
            } elseif (($def['type'] ?? null) === 'list' && is_array($value)) {
                foreach ($value as $item) {
                    if (is_array($item)) array_push($found, ...self::richtextIn($def['of'] ?? [], $item));
                }
            }
        }
        return $found;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        if (is_string($value)) return [$value];
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * Campi tolti o rinominati: i template che li usano ancora vanno aggiornati.
     *
     * @return list<array{name: string, context: string}> nome e contesto (tipo ed elenchi, vedi TemplateUsage)
     */
    public static function retiredNames(array $op): array
    {
        $name = match ($op['op'] ?? '') {
            'remove_field' => (string) ($op['name'] ?? ''),
            'rename_field' => (string) ($op['from'] ?? ''),
            default => null,
        };
        if ($name === null) return [];
        return [['name' => $name, 'context' => TemplateUsage::context((string) ($op['collection'] ?? ''), (array) ($op['path'] ?? []))]];
    }

    /** Vero se il campo ha un solo valore per tutte le lingue (formato 2: non localized). */
    public static function shared(array $def): bool
    {
        return ($def['type'] ?? '') !== 'list' && empty($def['localized']);
    }

    // --- contenuti -----------------------------------------------------------

    private static function setField(array $state, array $op, array $ctx): array
    {
        $path = self::path($op);
        $key = (string) ($op['key'] ?? '');
        $fields = self::schemaAt($state['schema'], $key, self::listNames($path));
        $def = self::fieldDef($fields, (string) ($op['field'] ?? ''));
        if ($def['type'] === 'list') {
            throw new StagingException("«{$def['name']}» è un elenco: usa add_item, remove_item o move_item.");
        }
        $lang = isset($op['lang']) ? (string) $op['lang'] : null;
        if ($lang !== null && self::shared($def)) {
            throw new StagingException("«{$def['name']}» ha un solo valore per tutte le lingue: togli \"lang\".");
        }
        if ($lang !== null && !in_array($lang, $ctx['languages'], true)) {
            throw new StagingException("La lingua '{$lang}' non è attiva sul sito. Lingue attive: " . implode(', ', $ctx['languages']) . '.');
        }

        // Fra le sezioni di Kris si scrivono i valori di kris_site, kris_legal, kris_notfound e kris_wip; lo schema no
        $item = &self::item($state['content'], $key, (int) ($op['id'] ?? 0), $path, in_array($key, self::WRITABLE_RESERVED, true));
        $current = $item['values'][$def['name']] ?? null;
        $before = $lang === null ? $current : (is_array($current) ? ($current[$lang] ?? null) : null);

        if ($ctx['replay']) {
            if (!$ctx['force'] && ($op['before'] ?? null) !== $before) {
                throw new StagingException(self::conflict($op, 'il valore è stato cambiato sul sito nel frattempo'));
            }
        } else {
            $op['before'] = $before;
        }

        if ($lang === null) {
            $value = self::value($def, $op['value'] ?? null, $ctx);
        } else {
            $value = is_array($current) && !array_is_list($current) ? $current : array_fill_keys($ctx['languages'], self::emptyOf($def));
            $value[$lang] = self::one($def, $op['value'] ?? null, $ctx);
        }
        if (!isset($item['values']) || !is_array($item['values']) || array_is_list($item['values'])) $item['values'] = [];
        $item['values'][$def['name']] = $value;
        return [$state, $op];
    }

    private static function addItem(array $state, array $op, array $ctx): array
    {
        $path = self::path($op);
        $listName = (string) ($op['array'] ?? '');
        $def = self::fieldDef(self::schemaAt($state['schema'], (string) ($op['key'] ?? ''), self::listNames($path)), $listName);
        if ($def['type'] !== 'list') {
            throw new StagingException("«{$listName}» non è un elenco.");
        }
        $item = &self::item($state['content'], (string) $op['key'], (int) ($op['id'] ?? 0), $path);
        if (!is_array($item['values'][$listName] ?? null)) $item['values'][$listName] = [];
        $items = &$item['values'][$listName];

        $newId = $items ? max(array_map(fn($c) => (int) ($c['id'] ?? 0), $items)) + 1 : 0;
        if ($ctx['replay'] && isset($op['assigned_id'])) {
            // Le operazioni successive si riferiscono all'id avuto nell'anteprima: deve restare quello
            if (self::itemPosition($items, (int) $op['assigned_id']) !== null) {
                if ($ctx['force']) return [$state, $op];
                throw new StagingException(self::conflict($op, "sul sito esiste già un elemento con id {$op['assigned_id']}"));
            }
            $newId = (int) $op['assigned_id'];
        }
        $new = ['id' => $newId, 'values' => self::fill($def['of'] ?? [], (array) ($op['fields'] ?? []), $ctx)];
        $position = $op['position'] ?? 'end';
        $at = $position === 'start' ? 0 : (is_int($position) ? max(0, min($position, count($items))) : count($items));
        array_splice($items, $at, 0, [$new]);

        if (!$ctx['replay']) $op['assigned_id'] = $newId;
        return [$state, $op];
    }

    private static function removeItem(array $state, array $op, array $ctx): array
    {
        $item = &self::item($state['content'], (string) ($op['key'] ?? ''), (int) ($op['id'] ?? 0), self::path($op));
        $list = (string) ($op['array'] ?? '');
        $pos = self::itemPosition(is_array($item['values'][$list] ?? null) ? $item['values'][$list] : [], (int) ($op['item_id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "l'elemento non esiste più"));
        }
        array_splice($item['values'][$list], $pos, 1);
        return [$state, $op];
    }

    private static function moveItem(array $state, array $op, array $ctx): array
    {
        $item = &self::item($state['content'], (string) ($op['key'] ?? ''), (int) ($op['id'] ?? 0), self::path($op));
        $list = (string) ($op['array'] ?? '');
        $items = is_array($item['values'][$list] ?? null) ? $item['values'][$list] : [];
        $pos = self::itemPosition($items, (int) ($op['item_id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "l'elemento non esiste più"));
        }
        $moved = $items[$pos];
        array_splice($items, $pos, 1);
        array_splice($items, max(0, min((int) ($op['to'] ?? 0), count($items))), 0, [$moved]);
        $item['values'][$list] = $items;
        return [$state, $op];
    }

    private static function createEntity(array $state, array $op, array $ctx): array
    {
        $key = (string) ($op['key'] ?? '');
        self::assertUserCollection($key);
        $type = self::type($state, $key);
        $ids = array_map(fn($e) => (int) $e['id'], array_filter($state['content'], fn($e) => ($e['type'] ?? null) === $key));
        if ($ids && !empty($type['single']) && !($ctx['replay'] && $ctx['force'])) {
            throw new StagingException("«{$key}» ha un contenuto unico: non ne riceve un secondo.");
        }
        $newId = $ids ? max($ids) + 1 : 0;
        if ($ctx['replay'] && isset($op['assigned_id'])) {
            if (in_array((int) $op['assigned_id'], $ids, true)) {
                if ($ctx['force']) return [$state, $op];
                throw new StagingException(self::conflict($op, "sul sito esiste già un contenuto con id {$op['assigned_id']}"));
            }
            $newId = (int) $op['assigned_id'];
        }
        // Dopo l'ultimo contenuto dello stesso tipo: i contenuti di un tipo restano vicini nell'archivio
        $last = null;
        foreach ($state['content'] as $i => $e) {
            if (($e['type'] ?? null) === $key) $last = $i;
        }
        $new = ['type' => $key, 'id' => $newId, 'values' => self::fill($type['fields'] ?? [], (array) ($op['fields'] ?? []), $ctx)];
        array_splice($state['content'], $last === null ? count($state['content']) : $last + 1, 0, [$new]);
        $state['content'] = array_values($state['content']);
        if (!$ctx['replay']) $op['assigned_id'] = $newId;
        return [$state, $op];
    }

    private static function removeEntity(array $state, array $op, array $ctx): array
    {
        $key = (string) ($op['key'] ?? '');
        self::assertUserCollection($key);
        if (!empty($state['schema']['types'][$key]['single']) && !($ctx['replay'] && $ctx['force'])) {
            // Eliminarlo svuoterebbe la pagina
            throw new StagingException("«{$key}» ha un contenuto unico: non si elimina.");
        }
        $pos = self::rootPosition($state['content'], $key, (int) ($op['id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, 'il contenuto non esiste più'));
        }
        array_splice($state['content'], $pos, 1);
        return [$state, $op];
    }

    /** Sposta un contenuto fra quelli del suo tipo; gli altri tipi restano dove sono. */
    private static function moveEntity(array $state, array $op, array $ctx): array
    {
        $key = (string) ($op['key'] ?? '');
        self::assertUserCollection($key);
        $slots = array_keys(array_filter($state['content'], fn($e) => ($e['type'] ?? null) === $key));
        $items = array_map(fn($i) => $state['content'][$i], $slots);
        $pos = self::itemPosition($items, (int) ($op['id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, 'il contenuto non esiste più'));
        }
        $moved = $items[$pos];
        array_splice($items, $pos, 1);
        array_splice($items, max(0, min((int) ($op['to'] ?? 0), count($items))), 0, [$moved]);
        foreach ($slots as $n => $i) $state['content'][$i] = $items[$n];
        return [$state, $op];
    }

    /** Sospende o rende visibile un contenuto o un elemento di un elenco. */
    private static function setHidden(array $state, array $op, array $ctx): array
    {
        try {
            $item = &self::item($state['content'], (string) ($op['key'] ?? ''), (int) ($op['id'] ?? 0), self::path($op));
        } catch (StagingException $e) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "l'elemento non esiste più"));
        }
        if (!empty($op['hidden'])) {
            // hidden accanto a tipo e id, prima dei valori
            $item = array_intersect_key($item, ['type' => 1, 'id' => 1]) + ['hidden' => true] + $item;
        } else {
            unset($item['hidden']);
        }
        unset($item);
        return [$state, $op];
    }

    // --- schema --------------------------------------------------------------

    private static function addField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $field = self::validDef((array) ($op['field'] ?? []), $collection);
        $fields = &self::schemaRef($state['schema'], $collection, $names);
        if (self::defPosition($fields, $field['name']) !== null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$field['name']}» esiste già"));
        }
        $fields[] = $field;
        // Ogni contenuto ha tutti i campi del suo tipo, anche vuoti
        self::eachItem($state['content'], $collection, $names, function (array &$item) use ($field, $ctx): void {
            if (!array_key_exists($field['name'], $item['values'] ?? [])) $item['values'][$field['name']] = self::emptyValue($field, $ctx['languages']);
        });
        return [$state, $op];
    }

    private static function removeField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $name = (string) ($op['name'] ?? '');
        self::assertUserCollection($collection);
        $fields = &self::schemaRef($state['schema'], $collection, $names);
        $pos = self::defPosition($fields, $name);
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$name}» non esiste"));
        }
        array_splice($fields, $pos, 1);
        self::eachItem($state['content'], $collection, $names, function (array &$item) use ($name): void {
            unset($item['values'][$name]);
        });
        return [$state, $op];
    }

    private static function renameField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $from = (string) ($op['from'] ?? '');
        $to = (string) ($op['to'] ?? '');
        self::assertUserCollection($collection);
        self::assertFieldName($to);
        $fields = &self::schemaRef($state['schema'], $collection, $names);
        $pos = self::defPosition($fields, $from);
        if ($pos === null || self::defPosition($fields, $to) !== null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, $pos === null ? "il campo «{$from}» non esiste" : "esiste già un campo «{$to}»"));
        }
        $fields[$pos]['name'] = $to;
        self::eachItem($state['content'], $collection, $names, function (array &$item) use ($from, $to): void {
            if (!array_key_exists($from, $item['values'] ?? [])) return;
            // Stessa posizione fra i valori
            $values = [];
            foreach ($item['values'] as $name => $value) $values[$name === $from ? $to : $name] = $value;
            $item['values'] = $values;
        });
        return [$state, $op];
    }

    private static function describeField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        self::assertUserCollection($collection);
        $fields = &self::schemaRef($state['schema'], $collection, array_map('strval', (array) ($op['path'] ?? [])));
        $pos = self::defPosition($fields, (string) ($op['name'] ?? ''));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$op['name']}» non esiste"));
        }
        if (array_key_exists('description', $op)) {
            $fields[$pos]['description'] = mb_substr((string) $op['description'], 0, 300);
        }
        // La sezione raggruppa i campi nell'editor; vuota la toglie
        if (array_key_exists('section', $op)) {
            $section = self::section($op['section']);
            if ($section === '') {
                unset($fields[$pos]['section']);
            } else {
                $fields[$pos]['section'] = $section;
            }
        }
        return [$state, $op];
    }

    private static function createCollection(array $state, array $op, array $ctx): array
    {
        $name = (string) ($op['name'] ?? '');
        self::assertFieldName($name);
        self::assertUserCollection($name);
        if (isset($state['schema']['types'][$name])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "la raccolta «{$name}» esiste già"));
        }
        $fields = [];
        foreach ((array) ($op['fields'] ?? []) as $def) {
            $def = self::validDef((array) $def, $name);
            if (self::defPosition($fields, $def['name']) !== null) {
                throw new StagingException("Il campo «{$def['name']}» compare due volte.");
            }
            $fields[] = $def;
        }
        $state['schema']['types'][$name] = ['fields' => $fields];
        return [$state, $op];
    }

    /** Elimina una raccolta: schema, contenuti, pagine che la rendono ed elenchi promossi. */
    private static function removeCollection(array $state, array $op, array $ctx): array
    {
        $name = (string) ($op['name'] ?? '');
        self::assertUserCollection($name);
        if (!isset($state['schema']['types'][$name])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "la raccolta «{$name}» non esiste"));
        }
        unset($state['schema']['types'][$name]);
        $state['content'] = array_values(array_filter($state['content'], fn($e) => ($e['type'] ?? null) !== $name));
        $state['schema']['pages'] = array_filter($state['schema']['pages'], fn($p) => !is_array($p) || ($p['type'] ?? null) !== $name);
        if (isset($state['schema']['promoted'])) {
            $state['schema']['promoted'] = array_values(array_filter((array) $state['schema']['promoted'], fn($p) => ($p['from'] ?? null) !== $name && ($p['to'] ?? null) !== $name));
            if (!$state['schema']['promoted']) unset($state['schema']['promoted']);
        }
        return [$state, $op];
    }

    /** Rinomina una raccolta: schema, contenuti, pagine ed elenchi promossi. I template li aggiorna chi la rinomina. */
    private static function renameCollection(array $state, array $op, array $ctx): array
    {
        $from = (string) ($op['from'] ?? '');
        $to = (string) ($op['to'] ?? '');
        self::assertUserCollection($from);
        self::assertFieldName($to);
        self::assertUserCollection($to);
        $types = $state['schema']['types'];
        if (!isset($types[$from]) || isset($types[$to])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, !isset($types[$from]) ? "la raccolta «{$from}» non esiste" : "esiste già una raccolta «{$to}»"));
        }
        $renamed = [];
        foreach ($types as $key => $type) $renamed[$key === $from ? $to : $key] = $type;
        $state['schema']['types'] = $renamed;
        foreach ($state['content'] as &$item) {
            if (($item['type'] ?? null) === $from) $item['type'] = $to;
        }
        unset($item);
        foreach ($state['schema']['pages'] as &$page) {
            if (is_array($page) && ($page['type'] ?? null) === $from) $page['type'] = $to;
        }
        unset($page);
        foreach ((array) ($state['schema']['promoted'] ?? []) as $i => $p) {
            foreach (['from', 'to'] as $k) {
                if (($p[$k] ?? null) === $from) $state['schema']['promoted'][$i][$k] = $to;
            }
        }
        return [$state, $op];
    }

    /**
     * Cambia il tipo di un campo, o se ha un valore per lingua, e converte i
     * valori: un valore condiviso diventa lo stesso in ogni lingua, uno per
     * lingua diventa condiviso con la prima lingua non vuota, text e richtext
     * si convertono (escape o testo senza tag). Da o verso elenco,
     * interruttore o scelta solo se il campo è vuoto in tutti i contenuti.
     *
     * "type" accetta i nomi della 1.x: plain è text condiviso; text,
     * richtext e media senza "localized" diventano per lingua.
     */
    private static function changeType(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $name = (string) ($op['name'] ?? '');
        $asked = (string) ($op['type'] ?? '');
        $type = self::ALIASES[$asked] ?? $asked;
        self::assertUserCollection($collection);
        if (!in_array($type, self::TYPES, true)) {
            throw new StagingException("Tipo di campo non valido: '{$asked}'. Tipi ammessi: " . implode(', ', self::TYPES) . '.');
        }
        $fields = &self::schemaRef($state['schema'], $collection, $names);
        $pos = self::defPosition($fields, $name);
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$name}» non esiste"));
        }
        $old = $fields[$pos];
        $from = self::label($old);
        if ($ctx['replay']) {
            if (!$ctx['force'] && ($op['from'] ?? null) !== $from) {
                throw new StagingException(self::conflict($op, 'il tipo del campo è stato cambiato sul sito nel frattempo'));
            }
        } else {
            $op['from'] = $from;
        }
        $localized = match (true) {
            $type === 'list' => null,
            array_key_exists('localized', $op) => (bool) $op['localized'],
            $asked === 'plain' => false,
            in_array($type, ['text', 'richtext', 'media'], true) => true,
            default => !empty($old['localized']),
        };
        $new = ['name' => $name, 'type' => $type] + ($type === 'list' ? ['of' => []] : ['localized' => $localized])
            + array_intersect_key($old, array_flip(['description', 'section']));
        if (self::label($new) === $from) return [$state, $op];
        $choices = ['list', 'boolean', 'select'];
        if ($old['type'] !== $type && (in_array($old['type'], $choices, true) || in_array($type, $choices, true))) {
            if ($type === 'select') {
                throw new StagingException("Per un campo a scelta servono le opzioni: aggiungilo con add_field invece di convertire «{$name}».");
            }
            $filled = false;
            self::eachItem($state['content'], $collection, $names, function (array &$item) use ($name, &$filled): void {
                if (!self::isEmptyValue($item['values'][$name] ?? null)) $filled = true;
            });
            if ($filled) {
                throw new StagingException("«{$name}» contiene valori: un campo si converte da o verso un elenco, un interruttore o una scelta solo quando è vuoto in tutti i contenuti.");
            }
        }
        if ($old['type'] === $type && $type === 'select') $new += array_intersect_key($old, array_flip(['options', 'multiple']));
        $fields[$pos] = $new;
        unset($fields);
        $languages = $ctx['languages'];
        self::eachItem($state['content'], $collection, $names, function (array &$item) use ($name, $old, $new, $languages): void {
            if (!array_key_exists($name, $item['values'] ?? [])) return;
            $item['values'][$name] = self::convertValue($item['values'][$name], $old, $new, $languages);
        });
        return [$state, $op];
    }

    /** Tipo leggibile di un campo, per il confronto alla ripetizione: "text", "text per lingua". */
    private static function label(array $def): string
    {
        return $def['type'] . (!empty($def['localized']) ? ' per lingua' : '');
    }

    private static function convertValue(mixed $value, array $from, array $to, array $languages): mixed
    {
        if ($from['type'] !== $to['type'] && (in_array($to['type'], ['list', 'boolean', 'select'], true) || in_array($from['type'], ['list', 'boolean', 'select'], true))) {
            return self::emptyValue($to, $languages);
        }
        if ($from['type'] === $to['type'] && in_array($to['type'], ['boolean', 'select'], true)) {
            // Stessa scelta, condivisa o per lingua
            if (!empty($to['localized']) && empty($from['localized'])) return array_fill_keys($languages, $value);
            if (empty($to['localized']) && is_array($value) && !array_is_list($value)) return $value[$languages[0]] ?? (array_values($value)[0] ?? self::emptyOf($to));
            return $value;
        }
        $convert = function (string $text) use ($from, $to): string {
            if ($from['type'] === 'richtext' && $to['type'] !== 'richtext') {
                $text = str_ireplace(['<br>', '<br/>', '<br />', '</p>'], "\n", $text);
                return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            }
            if ($to['type'] === 'richtext' && $from['type'] !== 'richtext') {
                return $text === '' ? '' : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'), false);
            }
            return $text;
        };
        if (empty($to['localized'])) {
            if (!is_array($value)) return $convert((string) $value);
            foreach ($languages as $lang) {
                if (isset($value[$lang]) && $value[$lang] !== '') return $convert((string) $value[$lang]);
            }
            foreach ($value as $text) {
                if (is_string($text) && $text !== '') return $convert($text);
            }
            return '';
        }
        $out = [];
        foreach ($languages as $lang) {
            $text = is_array($value) ? (string) ($value[$lang] ?? '') : (string) $value;
            $out[$lang] = $convert($text);
        }
        if (is_array($value)) {
            // Le lingue disattivate conservano i loro valori, come nell'editor
            foreach ($value as $lang => $text) {
                if (!array_key_exists($lang, $out) && is_string($text)) $out[$lang] = $convert($text);
            }
        }
        return $out;
    }

    private static function isEmptyValue(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                if (!self::isEmptyValue($v)) return false;
            }
            return true;
        }
        return $value === null || $value === '' || $value === false;
    }

    /** Sposta un campo nello schema: cambia solo l'ordine nell'editor. */
    private static function moveField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        self::assertUserCollection($collection);
        $fields = &self::schemaRef($state['schema'], $collection, array_map('strval', (array) ($op['path'] ?? [])));
        $pos = self::defPosition($fields, (string) ($op['name'] ?? ''));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$op['name']}» non esiste"));
        }
        $list = array_values($fields);
        $field = $list[$pos];
        array_splice($list, $pos, 1);
        array_splice($list, max(0, min((int) ($op['to'] ?? 0), count($list))), 0, [$field]);
        $fields = $list;
        return [$state, $op];
    }

    /**
     * Opzioni e scelta multipla di un campo a scelta. Dai contenuti si tolgono
     * le opzioni non più ammesse; passando da multipla a singola resta la prima.
     */
    private static function setOptions(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $name = (string) ($op['name'] ?? '');
        self::assertUserCollection($collection);
        $fields = &self::schemaRef($state['schema'], $collection, $names);
        $pos = self::defPosition($fields, $name);
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$name}» non esiste"));
        }
        $old = $fields[$pos];
        if (($old['type'] ?? '') !== 'select') {
            throw new StagingException("«{$name}» non è un campo a scelta: le opzioni valgono solo per select.");
        }
        $def = self::validDef(['name' => $name, 'type' => 'select', 'localized' => !empty($old['localized']),
            'options' => $op['options'] ?? ($old['options'] ?? []),
            'multiple' => array_key_exists('multiple', $op) ? (bool) $op['multiple'] : !empty($old['multiple'])]
            + array_intersect_key($old, array_flip(['description', 'section'])), $collection);
        $fields[$pos] = $def;
        unset($fields);
        $keep = function (mixed $value) use ($def): mixed {
            $picked = is_array($value) ? $value : ($value === null || $value === '' ? [] : [(string) $value]);
            $picked = array_values(array_intersect($def['options'], array_map('strval', $picked)));
            return $def['multiple'] ? $picked : ($picked[0] ?? '');
        };
        self::eachItem($state['content'], $collection, $names, function (array &$item) use ($name, $def, $keep): void {
            if (!array_key_exists($name, $item['values'] ?? [])) return;
            $value = $item['values'][$name];
            $item['values'][$name] = $def['localized'] && is_array($value) && !array_is_list($value)
                ? array_map($keep, $value) : $keep($value);
        });
        return [$state, $op];
    }

    /** Nel formato 2 un elenco non sta in Posts: gli articoli sono una raccolta. */
    private static function setPosts(array $state, array $op, array $ctx): array
    {
        if ($ctx['replay'] && $ctx['force']) return [$state, $op];
        throw new StagingException('Un elenco non sta più in Posts: gli articoli sono una raccolta, con set_post_collection (i nuovi in cima).');
    }

    /** Una raccolta in Posts: un elenco di articoli, i nuovi in cima (newest_first). */
    private static function setPostCollection(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        self::assertUserCollection($collection);
        if (!isset($state['schema']['types'][$collection])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "la raccolta «{$collection}» non esiste"));
        }
        $type = $state['schema']['types'][$collection];
        unset($type['newest_first']);
        $state['schema']['types'][$collection] = self::typeAttrs($type, ['newest_first' => !empty($op['enabled']) ? true : null]);
        return [$state, $op];
    }

    /**
     * Nome visibile, descrizione e contenuto unico di una raccolta. Solo le
     * chiavi indicate; "" toglie nome o descrizione. Contenuto unico solo con
     * al massimo un contenuto.
     */
    private static function setCollectionInfo(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        self::assertUserCollection($collection);
        if (!isset($state['schema']['types'][$collection])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "la raccolta «{$collection}» non esiste"));
        }
        $type = $state['schema']['types'][$collection];
        $set = [];
        foreach (['label' => 60, 'description' => 200] as $key => $max) {
            if (!array_key_exists($key, $op)) continue;
            $text = mb_substr(trim((string) $op[$key]), 0, $max);
            unset($type[$key]);
            $set[$key] = $text === '' ? null : $text;
        }
        if (array_key_exists('single', $op)) {
            $count = count(array_filter($state['content'], fn($e) => ($e['type'] ?? null) === $collection));
            if (!empty($op['single']) && $count > 1) {
                throw new StagingException("«{$collection}» ha {$count} contenuti: il contenuto unico vale solo con uno.");
            }
            unset($type['single']);
            $set['single'] = !empty($op['single']) ? true : null;
        }
        $state['schema']['types'][$collection] = self::typeAttrs($type, $set);
        return [$state, $op];
    }

    /** Gli attributi di un tipo nell'ordine fisso (label, description, single, newest_first, reserved), poi i campi. */
    private static function typeAttrs(array $type, array $set): array
    {
        $out = [];
        foreach (['label', 'description', 'single', 'newest_first', 'reserved'] as $key) {
            $value = array_key_exists($key, $set) ? $set[$key] : ($type[$key] ?? null);
            if ($value !== null) $out[$key] = $value;
        }
        return $out + array_diff_key($type, $out) + ['fields' => []];
    }

    /** Posizione di una raccolta nell'editor: l'ordine dei tipi nello schema. */
    private static function moveCollection(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        self::assertUserCollection($collection);
        $types = $state['schema']['types'];
        if (!isset($types[$collection])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "la raccolta «{$collection}» non esiste"));
        }
        $order = array_values(array_filter(array_keys($types), fn($n) => !str_starts_with((string) $n, 'kris_') && !str_starts_with((string) $n, 'plg_') && $n !== $collection));
        array_splice($order, max(0, min((int) ($op['to'] ?? 0), count($order))), 0, [$collection]);
        $sorted = [];
        foreach ($order as $name) $sorted[$name] = $types[$name];
        // Le sezioni di Kris restano in fondo, nel loro ordine
        $state['schema']['types'] = $sorted + $types;
        return [$state, $op];
    }

    // --- pagine --------------------------------------------------------------

    /** Toglie una pagina dalle pagine pubbliche (e dalla sitemap). Il template resta. */
    /**
     * Cambia una pagina già pubblica: template, tipo che rende, contenuto fisso
     * (item, null per una pagina per contenuto), sitemap, priorità, campo dello
     * slug. Solo le chiavi mandate; il nome della pagina (e quindi l'indirizzo
     * e i link dei template) resta.
     */
    private static function setPage(array $state, array $op, array $ctx): array
    {
        $page = (string) ($op['page'] ?? '');
        $entry = $state['schema']['pages'][$page] ?? null;
        if (!is_array($entry)) {
            throw new StagingException(self::conflict($op, "«{$page}» non è una pagina pubblica: registrala con register_page"));
        }
        if (array_key_exists('template', $op)) {
            $template = (string) $op['template'];
            if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $template) !== 1) throw new StagingException("Nome di template non valido: '{$template}'.");
            $entry['template'] = $template;
        }
        if (array_key_exists('type', $op)) {
            $type = (string) $op['type'];
            if (str_starts_with($type, 'plg_') || !isset($state['schema']['types'][$type])) {
                throw new StagingException("La pagina non può rendere «{$type}»: scegli un tipo del sito.");
            }
            if ($type !== ($entry['type'] ?? null)) unset($entry['list'], $entry['slug']);
            $entry['type'] = $type;
        }
        if (array_key_exists('item', $op)) {
            if ($op['item'] === null) {
                if ($page === 'homepage') throw new StagingException('La homepage rende sempre un contenuto fisso.');
                unset($entry['item'], $entry['list']);
            } else {
                $entry['item'] = (int) $op['item'];
                unset($entry['slug']);
            }
        }
        if (array_key_exists('sitemap', $op)) $entry['sitemap'] = $op['sitemap'] === true;
        if (array_key_exists('priority', $op)) {
            if ($op['priority'] === null || $op['priority'] === '') {
                unset($entry['priority']);
            } elseif (!is_numeric($op['priority']) || (float) $op['priority'] < 0 || (float) $op['priority'] > 1) {
                throw new StagingException('La priorità della sitemap va da 0 a 1.');
            } else {
                $entry['priority'] = round((float) $op['priority'], 1);
            }
        }
        if (array_key_exists('slug', $op)) {
            $slug = (string) ($op['slug'] ?? '');
            if ($slug === '') {
                unset($entry['slug']);
            } else {
                if (isset($entry['item'])) throw new StagingException('Lo slug vale per le pagine con un indirizzo per contenuto, non per un contenuto fisso.');
                $def = null;
                foreach ($state['schema']['types'][$entry['type']]['fields'] ?? [] as $field) {
                    if (($field['name'] ?? null) === $slug) $def = $field;
                }
                if ($def === null || !in_array($def['type'] ?? '', ['text', 'plain'], true)) {
                    throw new StagingException("Lo slug viene da un campo di testo del tipo: «{$slug}» non lo è.");
                }
                $entry['slug'] = $slug;
            }
        }
        $state['schema']['pages'][$page] = self::pageOrder($entry);
        return [$state, $op];
    }

    private static function unregisterPage(array $state, array $op, array $ctx): array
    {
        $page = (string) ($op['page'] ?? '');
        if ($page === 'homepage') {
            throw new StagingException('La homepage è la pagina predefinita del sito: non si può togliere.');
        }
        if (!isset($state['schema']['pages'][$page])) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "«{$page}» non è una pagina pubblica"));
        }
        unset($state['schema']['pages'][$page]);
        return [$state, $op];
    }

    private static function registerPage(array $state, array $op, array $ctx): array
    {
        $page = (string) ($op['page'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $page) !== 1) {
            throw new StagingException("Nome di pagina non valido: '{$page}'.");
        }
        $current = $state['schema']['pages'][$page] ?? null;
        if (!array_key_exists('sitemap', $op)) {
            $state['schema']['pages'][$page] = is_array($current) ? $current : self::pageEntry($state['schema'], $page, false, null);
            return [$state, $op];
        }
        $state['schema']['pages'][$page] = self::pageEntry($state['schema'], $page, $op['sitemap'], is_array($current) ? $current : null);
        return [$state, $op];
    }

    /** Mette o toglie dalla sitemap una pagina già pubblica; una regola indica anche cosa rende. */
    private static function setSitemap(array $state, array $op, array $ctx): array
    {
        $page = (string) ($op['page'] ?? '');
        if (!isset($state['schema']['pages'][$page])) {
            throw new StagingException(self::conflict($op, "«{$page}» non è una pagina pubblica: registrala con register_page"));
        }
        if (!array_key_exists('sitemap', $op)) {
            throw new StagingException('set_sitemap vuole «sitemap»: false per toglierla, true o una regola ({"key", "id", "children"}) per metterla.');
        }
        $state['schema']['pages'][$page] = self::pageEntry($state['schema'], $page, $op['sitemap'], $state['schema']['pages'][$page]);
        return [$state, $op];
    }

    /**
     * La pagina dello schema: con false fuori dalla sitemap, con true nella
     * sitemap, con una regola {key, id, children} nella sitemap e con quello
     * che rende (il tipo, il contenuto fisso, l'elenco). Una pagina nuova
     * senza regola rende il contenuto 0 del tipo con il suo nome, o della
     * homepage.
     */
    private static function pageEntry(array $schema, string $page, mixed $sitemap, ?array $current): array
    {
        $entry = $current ?? ['template' => $page, 'type' => isset($schema['types'][$page]) ? $page : 'homepage', 'item' => 0];
        if ($sitemap === false || $sitemap === null || $sitemap === true) {
            $entry['sitemap'] = $sitemap === true;
            return self::pageOrder($entry);
        }
        if (!is_array($sitemap)) {
            throw new StagingException('«sitemap» è false, true o una regola con "key", "id" e "children".');
        }
        $unknown = array_diff(array_map('strval', array_keys($sitemap)), ['key', 'id', 'children']);
        if ($unknown) {
            throw new StagingException('La regola della sitemap non conosce ' . implode(', ', array_map(fn($k) => "«{$k}»", $unknown)) . '. Chiavi ammesse: key, id, children.');
        }
        $type = isset($sitemap['key']) ? (string) $sitemap['key'] : 'homepage';
        unset($entry['item'], $entry['list'], $entry['slug']);
        $entry['type'] = $type;
        if (isset($sitemap['children']) && (string) $sitemap['children'] !== '') {
            $entry += ['item' => (int) ($sitemap['id'] ?? 0), 'list' => (string) $sitemap['children']];
        } elseif (isset($sitemap['id']) || $type === 'homepage') {
            $entry['item'] = (int) ($sitemap['id'] ?? 0);
        }
        $entry['sitemap'] = true;
        return self::pageOrder($entry);
    }

    private static function pageOrder(array $entry): array
    {
        $out = [];
        foreach (['template', 'type', 'item', 'list', 'sitemap', 'priority', 'slug'] as $key) {
            if (array_key_exists($key, $entry)) $out[$key] = $entry[$key];
        }
        return $out + $entry;
    }

    // --- valori --------------------------------------------------------------

    /** Valore completo di un campo non elenco, controllato rispetto al tipo. */
    private static function value(array $def, mixed $value, array $ctx): mixed
    {
        if (self::shared($def)) return self::one($def, $value, $ctx, true);
        if (in_array($def['type'], ['boolean', 'select'], true) && (!is_array($value) || array_is_list($value))) {
            // Interruttore o scelta per lingua: un valore solo vale per tutte le lingue
            $value = array_fill_keys($ctx['languages'], $value);
        }
        // Una stringa sola vale per la lingua principale; per le altre serve un oggetto per lingua
        if (is_string($value)) {
            $value = [$ctx['languages'][0] => $value];
        }
        if (!is_array($value)) {
            throw new StagingException("«{$def['name']}» vuole un testo per lingua, per esempio {\"it\": \"…\"}.");
        }
        $out = array_fill_keys($ctx['languages'], self::emptyOf($def));
        foreach ($value as $lang => $text) {
            if (!in_array($lang, $ctx['languages'], true)) {
                throw new StagingException("La lingua '{$lang}' non è attiva sul sito.");
            }
            $out[$lang] = self::one($def, $text, $ctx);
        }
        $empty = array_keys(array_filter($out, fn($text) => $text === ''));
        if ($empty && count($empty) < count($out) && !$ctx['replay']) {
            $ctx['warnings'][] = "«{$def['name']}»: " . (count($empty) === 1 ? 'resta vuota la lingua ' : 'restano vuote le lingue ')
                . implode(', ', $empty) . ". Per scriverle passa un testo per lingua, per esempio {\"" . $ctx['languages'][0] . "\": \"…\", \"{$empty[0]}\": \"…\"}.";
        }
        return $out;
    }

    /**
     * Un valore (di una lingua, o condiviso): true/false per un interruttore,
     * l'opzione o le opzioni per una scelta, un testo per gli altri.
     */
    private static function one(array $def, mixed $value, array $ctx, bool $lenient = false): mixed
    {
        if ($def['type'] === 'boolean') {
            if (!in_array($value, [true, false, 1, 0, '1', '0', '', null], true)) {
                throw new StagingException("«{$def['name']}» è un interruttore: true o false.");
            }
            return in_array($value, [true, 1, '1'], true);
        }
        if ($def['type'] === 'select') {
            $picked = is_array($value) ? $value : ($value === null || $value === '' ? [] : explode(',', (string) $value));
            $picked = array_values(array_map(fn($v) => trim((string) $v), $picked));
            $options = array_map('strval', (array) ($def['options'] ?? []));
            $unknown = array_diff($picked, $options);
            if ($unknown) {
                throw new StagingException("«{$def['name']}» non ha l'opzione «" . implode('», «', $unknown) . '». Opzioni: ' . implode(', ', $options) . '.');
            }
            if (count($picked) > 1 && empty($def['multiple'])) {
                throw new StagingException("«{$def['name']}» ammette una sola opzione.");
            }
            // Nell'ordine delle opzioni
            $picked = array_values(array_intersect($options, $picked));
            return !empty($def['multiple']) ? $picked : ($picked[0] ?? '');
        }
        if ($lenient && !is_string($value)) {
            // Un campo condiviso accetta un numero o un sì/no: diventa testo
            if (!is_scalar($value) && $value !== null) {
                throw new StagingException("«{$def['name']}» ha un solo valore per tutte le lingue: serve un valore semplice.");
            }
            $value = $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        if (!is_string($value)) {
            throw new StagingException("Il valore di «{$def['name']}» deve essere un testo.");
        }
        if ($def['type'] === 'media' && $value !== '') {
            if (preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $value) !== 1 || !($ctx['uploadExists'])($value)) {
                throw new StagingException("«{$def['name']}» è un file: usa un file caricato, assets/uploads/<nome>. '{$value}' non esiste.");
            }
        }
        // Un testo per lingua è testo semplice; uno condiviso (codici, URL, snippet) esce comunque con l'escape
        if ($def['type'] === 'text' && !empty($def['localized']) && $value !== strip_tags($value)) {
            throw new StagingException("«{$def['name']}» è testo semplice: niente HTML (per l'HTML serve un campo richtext).");
        }
        // Il testo formattato si salva già filtrato: solo gli elementi e gli attributi ammessi
        return $def['type'] === 'richtext' ? \Kris\Template\Richtext::clean($value) : $value;
    }

    /**
     * Valori di un elemento nuovo: tutti i campi dello schema, vuoti se non
     * indicati. Un elenco annidato si passa come lista di elementi, ognuno con
     * i suoi campi: gli elementi ricevono gli id 0, 1, 2… nell'ordine dato.
     */
    private static function fill(array $fields, array $values, array $ctx): array
    {
        $out = [];
        foreach ($fields as $def) {
            if (!array_key_exists($def['name'], $values)) {
                $out[$def['name']] = self::emptyValue($def, $ctx['languages']);
            } elseif ($def['type'] === 'list') {
                $items = $values[$def['name']];
                if (!is_array($items) || !array_is_list($items)) {
                    throw new StagingException("«{$def['name']}» è un elenco: passa una lista di elementi, per esempio [{\"title\": {…}}, …].");
                }
                $list = [];
                foreach ($items as $i => $item) {
                    if (!is_array($item) || ($item && array_is_list($item))) {
                        throw new StagingException("L'elemento {$i} di «{$def['name']}» deve essere un oggetto con i suoi campi.");
                    }
                    $list[] = ['id' => $i, 'values' => self::fill($def['of'] ?? [], $item, $ctx)];
                }
                $out[$def['name']] = $list;
            } else {
                $out[$def['name']] = self::value($def, $values[$def['name']], $ctx);
            }
        }
        foreach (array_keys($values) as $name) {
            if (self::defPosition($fields, (string) $name) === null) {
                throw new StagingException("Il campo «{$name}» non esiste nello schema.");
            }
        }
        return $out;
    }

    /** Il valore vuoto di un campo: per lingua se il campo è localizzato. */
    public static function emptyValue(array $def, array $languages): mixed
    {
        if (($def['type'] ?? '') === 'list') return [];
        return self::shared($def) ? self::emptyOf($def) : array_fill_keys($languages, self::emptyOf($def));
    }

    private static function emptyOf(array $def): mixed
    {
        return match ($def['type'] ?? '') {
            'boolean' => false,
            'select' => !empty($def['multiple']) ? [] : '',
            'list' => [],
            default => '',
        };
    }

    // --- schema: regole ------------------------------------------------------

    /** Una definizione di campo del formato 2, dai nomi del formato 2 o della 1.x. */
    private static function validDef(array $def, string $collection): array
    {
        self::assertUserCollection($collection);
        $name = (string) ($def['name'] ?? '');
        $asked = (string) ($def['type'] ?? '');
        $type = self::ALIASES[$asked] ?? $asked;
        self::assertFieldName($name);
        if (!in_array($type, self::TYPES, true)) {
            throw new StagingException("Tipo di campo non valido: '{$asked}'. Tipi ammessi: " . implode(', ', self::TYPES) . '.');
        }
        $out = ['name' => $name, 'type' => $type];
        if ($type === 'list') {
            $of = [];
            foreach ((array) ($def['of'] ?? []) as $child) {
                $child = self::validDef((array) $child, $collection);
                if (self::defPosition($of, $child['name']) !== null) {
                    throw new StagingException("Il campo «{$child['name']}» compare due volte in «{$name}».");
                }
                $of[] = $child;
            }
            $out['of'] = $of;
        } else {
            $out['localized'] = array_key_exists('localized', $def)
                ? (bool) $def['localized']
                : ($asked !== 'plain' && in_array($type, ['text', 'richtext', 'media'], true));
        }
        if (isset($def['description'])) $out['description'] = mb_substr((string) $def['description'], 0, 300);
        $section = self::section($def['section'] ?? null);
        if ($section !== '') $out['section'] = $section;
        if ($type === 'select') {
            $options = array_values(array_unique(array_filter(array_map(fn($o) => trim((string) $o), (array) ($def['options'] ?? [])), fn($o) => $o !== '')));
            if (!$options) throw new StagingException("«{$name}» è una scelta: servono le opzioni, per esempio \"options\": [\"web\", \"app\"].");
            foreach ($options as $option) {
                if (str_contains($option, ',')) throw new StagingException("L'opzione «{$option}» di «{$name}» non può contenere virgole.");
            }
            $out['options'] = $options;
            $out['multiple'] = !empty($def['multiple']);
        }
        return $out;
    }

    private static function assertFieldName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new StagingException("Nome non valido: '{$name}'. Usa lettere minuscole, numeri e _ (inizia con una lettera).");
        }
        if (in_array($name, self::RESERVED_NAMES, true)) {
            throw new StagingException("«{$name}» è un nome riservato da Kris: scegline un altro.");
        }
    }

    /** Nome di una sezione dell'editor: spazi compattati, al massimo 60 caratteri. */
    private static function section(mixed $value): string
    {
        return is_string($value) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, 60) : '';
    }

    private static function assertUserCollection(string $collection): void
    {
        if (str_starts_with($collection, 'kris_')) {
            throw new StagingException("La raccolta «{$collection}» appartiene a Kris e non si modifica.");
        }
        if (str_starts_with($collection, 'plg_')) {
            throw new StagingException("La raccolta «{$collection}» appartiene a un plugin e non si modifica.");
        }
    }

    // --- navigazione ---------------------------------------------------------

    private static function path(array $op): array
    {
        $path = array_values((array) ($op['path'] ?? []));
        if (count($path) % 2 !== 0) {
            throw new StagingException('Percorso non valido: alterna nome dell\'elenco e id dell\'elemento.');
        }
        return $path;
    }

    /** I nomi degli elenchi in un percorso: ['servizi', 2, 'punti', 0] -> ['servizi', 'punti']. */
    private static function listNames(array $path): array
    {
        $names = [];
        for ($i = 0; $i < count($path); $i += 2) $names[] = (string) $path[$i];
        return $names;
    }

    private static function type(array $state, string $key): array
    {
        $type = $state['schema']['types'][$key] ?? null;
        if (!is_array($type)) throw new StagingException("La raccolta «{$key}» non esiste.");
        return $type;
    }

    /** I campi al livello indicato: del tipo, o di un elenco annidato. */
    private static function schemaAt(array $schema, string $type, array $listNames): array
    {
        $fields = $schema['types'][$type]['fields'] ?? null;
        if (!isset($schema['types'][$type]) || !is_array($fields)) {
            throw new StagingException("La raccolta «{$type}» non esiste.");
        }
        foreach ($listNames as $name) {
            $def = self::fieldDef($fields, $name);
            if ($def['type'] !== 'list') throw new StagingException("«{$name}» non è un elenco.");
            $fields = $def['of'] ?? [];
        }
        return $fields;
    }

    private static function &schemaRef(array &$schema, string $type, array $listNames): array
    {
        if (!isset($schema['types'][$type]) || !is_array($schema['types'][$type])) {
            throw new StagingException("La raccolta «{$type}» non esiste.");
        }
        if (!is_array($schema['types'][$type]['fields'] ?? null)) $schema['types'][$type]['fields'] = [];
        $fields = &$schema['types'][$type]['fields'];
        foreach ($listNames as $name) {
            $pos = self::defPosition($fields, $name);
            if ($pos === null || ($fields[$pos]['type'] ?? null) !== 'list') {
                throw new StagingException("«{$name}» non è un elenco di «{$type}».");
            }
            if (!isset($fields[$pos]['of'])) $fields[$pos]['of'] = [];
            $fields = &$fields[$pos]['of'];
        }
        return $fields;
    }

    private static function fieldDef(array $fields, string $name): array
    {
        $pos = self::defPosition($fields, $name);
        if ($pos === null) {
            throw new StagingException("Il campo «{$name}» non esiste nello schema.");
        }
        return array_values($fields)[$pos];
    }

    private static function defPosition(array $fields, string $name): ?int
    {
        foreach (array_values($fields) as $i => $def) {
            if (($def['name'] ?? null) === $name) return $i;
        }
        return null;
    }

    /** $reservedWritable: un valore di una sezione kris_ che si puo cambiare (vedi setField); il resto no. */
    private static function &item(array &$content, string $type, int $id, array $path, bool $reservedWritable = false): array
    {
        if (!($reservedWritable && !$path)) {
            self::assertUserCollection($type);
        }
        $found = null;
        foreach ($content as $i => $root) {
            if (($root['type'] ?? null) === $type && (int) ($root['id'] ?? -1) === $id) { $found = $i; break; }
        }
        if ($found === null) {
            throw new StagingException("Il contenuto «{$type}» con id {$id} non esiste.");
        }
        $cur = &$content[$found];
        for ($i = 0; $i < count($path); $i += 2) {
            $name = (string) $path[$i];
            $items = $cur['values'][$name] ?? [];
            $pos = self::itemPosition(is_array($items) ? $items : [], (int) $path[$i + 1]);
            if ($pos === null) {
                throw new StagingException("L'elemento {$path[$i]}/{$path[$i + 1]} non esiste.");
            }
            $cur = &$cur['values'][$name][$pos];
        }
        return $cur;
    }

    /** Chiama $fn su ogni contenuto al livello indicato: del tipo, o elementi dentro gli elenchi. */
    private static function eachItem(array &$content, string $type, array $listNames, callable $fn): void
    {
        foreach ($content as &$root) {
            if (($root['type'] ?? null) === $type) self::eachIn($root, $listNames, $fn);
        }
    }

    private static function eachIn(array &$item, array $listNames, callable $fn): void
    {
        if (!isset($item['values']) || !is_array($item['values'])) $item['values'] = [];
        if (!$listNames) {
            $fn($item);
            return;
        }
        $name = (string) $listNames[0];
        if (!is_array($item['values'][$name] ?? null)) return;
        foreach ($item['values'][$name] as &$child) {
            self::eachIn($child, array_slice($listNames, 1), $fn);
        }
    }

    private static function rootPosition(array $content, string $type, int $id): ?int
    {
        foreach (array_values($content) as $i => $root) {
            if (($root['type'] ?? null) === $type && (int) ($root['id'] ?? -1) === $id) return $i;
        }
        return null;
    }

    private static function itemPosition(array $items, int $id): ?int
    {
        foreach (array_values($items) as $i => $item) {
            if ((int) ($item['id'] ?? -1) === $id) return $i;
        }
        return null;
    }

    private static function where(array $op): string
    {
        $where = '«' . ($op['key'] ?? '?') . '» #' . (int) ($op['id'] ?? 0);
        $path = (array) ($op['path'] ?? []);
        return $path ? $where . ' › ' . implode('/', $path) : $where;
    }

    private static function schemaWhere(array $op): string
    {
        $path = (array) ($op['path'] ?? []);
        return '«' . ($op['collection'] ?? '?') . '»' . ($path ? ' › ' . implode(' › ', $path) : '');
    }

    private static function conflict(array $op, string $reason): string
    {
        return self::describe($op) . ': ' . $reason . '.';
    }
}

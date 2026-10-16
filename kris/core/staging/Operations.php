<?php
declare(strict_types=1);

namespace Kris\Staging;

/**
 * Operazioni su contenuti, schema e pagine, applicate a uno stato in memoria:
 *
 *   ['data' => k_data.json, 'model' => k_model.json,
 *    'pages' => elenco di config/allowed_pages.json, 'seo' => config/seo.json]
 *
 * Funzioni pure: nessun accesso al filesystem, cosi la stessa operazione si
 * applica all'anteprima quando viene registrata e ai dati veri del sito
 * quando lo staging viene pubblicato, magari giorni dopo.
 *
 * Un'operazione registra quello che ha trovato ('before'): se alla
 * ripetizione il sito non e piu cosi (il cliente ha cambiato lo stesso campo
 * dall'editor, o ha tolto l'elemento) e un conflitto.
 *
 * Percorsi: 'key' e 'id' individuano l'entita radice, 'path' alterna nome di
 * un campo array e id del figlio, come il parametro path delle pagine:
 * ['servizi', 2, 'punti', 0].
 */
final class Operations
{
    public const TYPES = ['plain', 'text', 'richtext', 'image', 'array'];

    // Gli stessi nomi che l'editor riserva ai propri controlli, piu quelli esposti dal motore dei template
    private const RESERVED_NAMES = ['id', 'language', 'group', 'path', 'save_entity', 'create_instance',
        'create_nested', 'delete_nested', 'delete_instance', 'reorder_root', 'reorder_nested',
        'save_structure', 'delete_collection', 'save_settings', 'delete_media', 'file_name',
        'order', 'group_name', 'schema_json', 'collection_name', 'langs', 'f', 'return_posts'];

    /**
     * Applica un'operazione. Alla prima applicazione (non $replay) completa
     * l'operazione con quanto trovato, da confrontare alla ripetizione.
     *
     * @param callable(string): bool $uploadExists un URL assets/uploads/... esiste (nel sito o nello staging)
     * @return array{0: array, 1: array} stato nuovo e operazione completata
     * @throws StagingException operazione non valida, o conflitto alla ripetizione
     */
    public static function apply(array $state, array $op, array $languages, callable $uploadExists, bool $replay = false, bool $force = false): array
    {
        $type = (string) ($op['op'] ?? '');
        $method = [
            'set_field' => 'setField', 'add_item' => 'addItem', 'remove_item' => 'removeItem',
            'move_item' => 'moveItem', 'create_entity' => 'createEntity',
            'remove_entity' => 'removeEntity', 'move_entity' => 'moveEntity', 'set_hidden' => 'setHidden',
            'add_field' => 'addField', 'remove_field' => 'removeField', 'rename_field' => 'renameField',
            'describe_field' => 'describeField', 'create_collection' => 'createCollection',
            'register_page' => 'registerPage',
        ][$type] ?? null;
        if ($method === null) {
            throw new StagingException("Operazione sconosciuta: '{$type}'.");
        }
        $ctx = ['languages' => array_values($languages), 'uploadExists' => $uploadExists, 'replay' => $replay, 'force' => $force];
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
            'register_page' => "Nuova pagina pubblica «{$op['page']}»" . (isset($op['sitemap']) ? ', aggiunta alla sitemap' : ''),
            default => (string) ($op['op'] ?? 'operazione'),
        };
    }

    /** Nomi di campi tolti o rinominati: i template che li usano ancora vanno aggiornati. */
    public static function retiredNames(array $op): array
    {
        return match ($op['op'] ?? '') {
            'remove_field' => [(string) $op['name']],
            'rename_field' => [(string) $op['from']],
            default => [],
        };
    }

    // --- contenuti -----------------------------------------------------------

    private static function setField(array $state, array $op, array $ctx): array
    {
        $path = self::path($op);
        $schema = self::schemaAt($state['model'], (string) ($op['key'] ?? ''), self::arrayNames($path));
        $def = self::fieldDef($schema, (string) ($op['field'] ?? ''));
        if ($def['type'] === 'array') {
            throw new StagingException("«{$def['name']}» è un elenco: usa add_item, remove_item o move_item.");
        }
        $lang = isset($op['lang']) ? (string) $op['lang'] : null;
        if ($lang !== null && $def['type'] === 'plain') {
            throw new StagingException("«{$def['name']}» è un campo plain: non ha lingue.");
        }
        if ($lang !== null && !in_array($lang, $ctx['languages'], true)) {
            throw new StagingException("La lingua '{$lang}' non è attiva sul sito. Lingue attive: " . implode(', ', $ctx['languages']) . '.');
        }

        $entity = &self::entity($state['data'], (string) $op['key'], (int) ($op['id'] ?? 0), $path, true);
        $index = self::fieldIndex($entity, $def['name']);
        $current = $index === null ? null : $entity['data'][$index]['value'];
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
            $value = is_array($current) ? $current : array_fill_keys($ctx['languages'], '');
            $value[$lang] = self::langString($def, $op['value'] ?? null, $ctx);
        }
        if ($index === null) {
            $entity['data'][] = ['name' => $def['name'], 'type' => $def['type'], 'value' => $value];
        } else {
            $entity['data'][$index]['value'] = $value;
        }
        return [$state, $op];
    }

    private static function addItem(array $state, array $op, array $ctx): array
    {
        $path = self::path($op);
        $arrayName = (string) ($op['array'] ?? '');
        $parentSchema = self::schemaAt($state['model'], (string) ($op['key'] ?? ''), self::arrayNames($path));
        $def = self::fieldDef($parentSchema, $arrayName);
        if ($def['type'] !== 'array') {
            throw new StagingException("«{$arrayName}» non è un elenco.");
        }
        $entity = &self::entity($state['data'], (string) $op['key'], (int) ($op['id'] ?? 0), $path);
        $index = self::fieldIndex($entity, $arrayName);
        if ($index === null) {
            $entity['data'][] = ['name' => $arrayName, 'type' => 'array', 'value' => []];
            $index = array_key_last($entity['data']);
        }
        $items = &$entity['data'][$index]['value'];

        $newId = $items ? max(array_map(fn($c) => (int) ($c['id'] ?? 0), $items)) + 1 : 0;
        if ($ctx['replay'] && isset($op['assigned_id'])) {
            // Le operazioni successive si riferiscono all'id avuto nell'anteprima: deve restare quello
            if (self::itemPosition($items, (int) $op['assigned_id']) !== null) {
                if ($ctx['force']) return [$state, $op];
                throw new StagingException(self::conflict($op, "sul sito esiste già un elemento con id {$op['assigned_id']}"));
            }
            $newId = (int) $op['assigned_id'];
        }
        $item =['id' => $newId, 'data' => self::fill($def['of'] ?? [], (array) ($op['fields'] ?? []), $ctx)];
        $position = $op['position'] ?? 'end';
        $at = $position === 'start' ? 0 : (is_int($position) ? max(0, min($position, count($items))) : count($items));
        array_splice($items, $at, 0, [$item]);

        if (!$ctx['replay']) $op['assigned_id'] = $newId;
        return [$state, $op];
    }

    private static function removeItem(array $state, array $op, array $ctx): array
    {
        $entity = &self::entity($state['data'], (string) ($op['key'] ?? ''), (int) ($op['id'] ?? 0), self::path($op));
        $index = self::fieldIndex($entity, (string) ($op['array'] ?? ''));
        $items = $index === null ? [] : $entity['data'][$index]['value'];
        $pos = self::itemPosition($items, (int) ($op['item_id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "l'elemento non esiste più"));
        }
        array_splice($entity['data'][$index]['value'], $pos, 1);
        return [$state, $op];
    }

    private static function moveItem(array $state, array $op, array $ctx): array
    {
        $entity = &self::entity($state['data'], (string) ($op['key'] ?? ''), (int) ($op['id'] ?? 0), self::path($op));
        $index = self::fieldIndex($entity, (string) ($op['array'] ?? ''));
        $items = $index === null ? [] : $entity['data'][$index]['value'];
        $pos = self::itemPosition($items, (int) ($op['item_id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "l'elemento non esiste più"));
        }
        $item = $items[$pos];
        array_splice($items, $pos, 1);
        array_splice($items, max(0, min((int) ($op['to'] ?? 0), count($items))), 0, [$item]);
        $entity['data'][$index]['value'] = $items;
        return [$state, $op];
    }

    private static function createEntity(array $state, array $op, array $ctx): array
    {
        $key = (string) ($op['key'] ?? '');
        self::assertUserCollection($key);
        if (!isset($state['model'][$key])) {
            throw new StagingException("La raccolta «{$key}» non esiste.");
        }
        $ids = array_map(fn($e) => (int) $e['id'], array_filter($state['data'], fn($e) => ($e['name'] ?? null) === $key));
        $newId = $ids ? max($ids) + 1 : 0;
        if ($ctx['replay'] && isset($op['assigned_id'])) {
            if (in_array((int) $op['assigned_id'], $ids, true)) {
                if ($ctx['force']) return [$state, $op];
                throw new StagingException(self::conflict($op, "sul sito esiste già un contenuto con id {$op['assigned_id']}"));
            }
            $newId = (int) $op['assigned_id'];
        }
        $state['data'][] = ['name' => $key, 'id' => $newId, 'data' => self::fill($state['model'][$key], (array) ($op['fields'] ?? []), $ctx)];
        if (!$ctx['replay']) $op['assigned_id'] = $newId;
        return [$state, $op];
    }

    private static function removeEntity(array $state, array $op, array $ctx): array
    {
        $key = (string) ($op['key'] ?? '');
        self::assertUserCollection($key);
        $pos = self::rootPosition($state['data'], $key, (int) ($op['id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, 'il contenuto non esiste più'));
        }
        array_splice($state['data'], $pos, 1);
        return [$state, $op];
    }

    /** Sposta un'entita radice fra quelle della sua raccolta; le altre raccolte restano dove sono. */
    private static function moveEntity(array $state, array $op, array $ctx): array
    {
        $key = (string) ($op['key'] ?? '');
        self::assertUserCollection($key);
        $slots = array_keys(array_filter($state['data'], fn($e) => ($e['name'] ?? null) === $key));
        $entities = array_map(fn($i) => $state['data'][$i], $slots);
        $pos = self::itemPosition($entities, (int) ($op['id'] ?? -1));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, 'il contenuto non esiste più'));
        }
        $entity = $entities[$pos];
        array_splice($entities, $pos, 1);
        array_splice($entities, max(0, min((int) ($op['to'] ?? 0), count($entities))), 0, [$entity]);
        foreach ($slots as $n => $i) $state['data'][$i] = $entities[$n];
        return [$state, $op];
    }

    /** Sospende o rende visibile un'entita radice o un elemento di un elenco. */
    private static function setHidden(array $state, array $op, array $ctx): array
    {
        try {
            $entity = &self::entity($state['data'], (string) ($op['key'] ?? ''), (int) ($op['id'] ?? 0), self::path($op));
        } catch (StagingException $e) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "l'elemento non esiste più"));
        }
        if (!empty($op['hidden'])) {
            $entity['hidden'] = true;
        } else {
            unset($entity['hidden']);
        }
        unset($entity);
        return [$state, $op];
    }

    // --- schema --------------------------------------------------------------

    private static function addField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $field = self::validDef((array) ($op['field'] ?? []), $collection);
        $schema = &self::schemaRef($state['model'], $collection, $names);
        foreach ($schema as $existing) {
            if (($existing['name'] ?? null) === $field['name']) {
                if ($ctx['replay'] && $ctx['force']) return [$state, $op];
                throw new StagingException(self::conflict($op, "il campo «{$field['name']}» esiste già"));
            }
        }
        $schema[] = $field;
        // Anche un campo vuoto deve esistere nei contenuti: un k-array su un campo
        // mancante cercherebbe una raccolta con lo stesso nome
        self::eachEntity($state['data'], $collection, $names, function (array &$entity) use ($field, $ctx): void {
            if (self::fieldIndex($entity, $field['name']) === null) {
                $entity['data'][] = self::emptyValue($field, $ctx['languages']);
            }
        });
        return [$state, $op];
    }

    private static function removeField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        $names = array_map('strval', (array) ($op['path'] ?? []));
        $name = (string) ($op['name'] ?? '');
        self::assertUserCollection($collection);
        $schema = &self::schemaRef($state['model'], $collection, $names);
        $pos = self::defPosition($schema, $name);
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$name}» non esiste"));
        }
        array_splice($schema, $pos, 1);
        self::eachEntity($state['data'], $collection, $names, function (array &$entity) use ($name): void {
            $i = self::fieldIndex($entity, $name);
            if ($i !== null) array_splice($entity['data'], $i, 1);
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
        $schema = &self::schemaRef($state['model'], $collection, $names);
        $pos = self::defPosition($schema, $from);
        if ($pos === null || self::defPosition($schema, $to) !== null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, $pos === null ? "il campo «{$from}» non esiste" : "esiste già un campo «{$to}»"));
        }
        $schema[$pos]['name'] = $to;
        self::eachEntity($state['data'], $collection, $names, function (array &$entity) use ($from, $to): void {
            $i = self::fieldIndex($entity, $from);
            if ($i !== null) $entity['data'][$i]['name'] = $to;
        });
        return [$state, $op];
    }

    private static function describeField(array $state, array $op, array $ctx): array
    {
        $collection = (string) ($op['collection'] ?? '');
        self::assertUserCollection($collection);
        $schema = &self::schemaRef($state['model'], $collection, array_map('strval', (array) ($op['path'] ?? [])));
        $pos = self::defPosition($schema, (string) ($op['name'] ?? ''));
        if ($pos === null) {
            if ($ctx['replay'] && $ctx['force']) return [$state, $op];
            throw new StagingException(self::conflict($op, "il campo «{$op['name']}» non esiste"));
        }
        if (array_key_exists('description', $op)) {
            $schema[$pos]['description'] = mb_substr((string) $op['description'], 0, 300);
        }
        // La sezione raggruppa i campi nell'editor; vuota la toglie
        if (array_key_exists('section', $op)) {
            $section = self::section($op['section']);
            if ($section === '') {
                unset($schema[$pos]['section']);
            } else {
                $schema[$pos]['section'] = $section;
            }
        }
        return [$state, $op];
    }

    private static function createCollection(array $state, array $op, array $ctx): array
    {
        $name = (string) ($op['name'] ?? '');
        self::assertFieldName($name);
        self::assertUserCollection($name);
        if (isset($state['model'][$name])) {
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
        $state['model'][$name] = $fields;
        return [$state, $op];
    }

    // --- pagine --------------------------------------------------------------

    private static function registerPage(array $state, array $op, array $ctx): array
    {
        $page = (string) ($op['page'] ?? '');
        if (preg_match('/^[a-z0-9][a-z0-9_-]*$/', $page) !== 1) {
            throw new StagingException("Nome di pagina non valido: '{$page}'.");
        }
        if (!in_array($page, $state['pages'], true)) {
            $state['pages'][] = $page;
        }
        if (isset($op['sitemap'])) {
            $entry = ['page' => $page];
            foreach (['key', 'children'] as $k) {
                if (isset($op['sitemap'][$k])) $entry[$k] = (string) $op['sitemap'][$k];
            }
            if (isset($op['sitemap']['id'])) $entry['id'] = (int) $op['sitemap']['id'];
            $sitemap = is_array($state['seo']['sitemap'] ?? null) ? $state['seo']['sitemap'] : [['page' => 'homepage']];
            if (!in_array($entry, $sitemap, true)) $sitemap[] = $entry;
            $state['seo']['sitemap'] = $sitemap;
        }
        return [$state, $op];
    }

    // --- valori --------------------------------------------------------------

    /** Valore completo di un campo non array, controllato rispetto al tipo. */
    private static function value(array $def, mixed $value, array $ctx): mixed
    {
        if ($def['type'] === 'plain') {
            if (!is_scalar($value) && $value !== null) {
                throw new StagingException("«{$def['name']}» è un campo plain: serve un valore semplice.");
            }
            return $value === null ? '' : (is_bool($value) ? ($value ? '1' : '0') : (string) $value);
        }
        // Una stringa sola vale per la lingua principale; per le altre serve un oggetto per lingua
        if (is_string($value)) {
            $value = [$ctx['languages'][0] => $value];
        }
        if (!is_array($value)) {
            throw new StagingException("«{$def['name']}» vuole un testo per lingua, per esempio {\"it\": \"…\"}.");
        }
        $out = array_fill_keys($ctx['languages'], '');
        foreach ($value as $lang => $text) {
            if (!in_array($lang, $ctx['languages'], true)) {
                throw new StagingException("La lingua '{$lang}' non è attiva sul sito.");
            }
            $out[$lang] = self::langString($def, $text, $ctx);
        }
        return $out;
    }

    private static function langString(array $def, mixed $text, array $ctx): string
    {
        if (!is_string($text)) {
            throw new StagingException("Il valore di «{$def['name']}» deve essere un testo.");
        }
        if ($def['type'] === 'image' && $text !== '') {
            if (preg_match('#^assets/uploads/[A-Za-z0-9._-]+$#', $text) !== 1 || !($ctx['uploadExists'])($text)) {
                throw new StagingException("«{$def['name']}» è un'immagine: usa un file caricato, assets/uploads/<nome>. '{$text}' non esiste.");
            }
        }
        if ($def['type'] === 'text' && $text !== strip_tags($text)) {
            throw new StagingException("«{$def['name']}» è testo semplice: niente HTML (per l'HTML serve un campo richtext).");
        }
        return $text;
    }

    /** Campi di un elemento nuovo: tutti quelli dello schema, vuoti se non indicati. */
    private static function fill(array $schema, array $fields, array $ctx): array
    {
        $out = [];
        foreach ($schema as $def) {
            if (array_key_exists($def['name'], $fields) && $def['type'] !== 'array') {
                $out[] = ['name' => $def['name'], 'type' => $def['type'], 'value' => self::value($def, $fields[$def['name']], $ctx)];
            } else {
                $out[] = self::emptyValue($def, $ctx['languages']);
            }
        }
        foreach (array_keys($fields) as $name) {
            if (self::defPosition($schema, (string) $name) === null) {
                throw new StagingException("Il campo «{$name}» non esiste nello schema.");
            }
        }
        return $out;
    }

    private static function emptyValue(array $def, array $languages): array
    {
        $value = match ($def['type']) {
            'array' => [],
            'plain' => '',
            default => array_fill_keys($languages, ''),
        };
        return ['name' => $def['name'], 'type' => $def['type'], 'value' => $value];
    }

    // --- schema: regole ------------------------------------------------------

    private static function validDef(array $def, string $collection): array
    {
        self::assertUserCollection($collection);
        $name = (string) ($def['name'] ?? '');
        $type = (string) ($def['type'] ?? '');
        self::assertFieldName($name);
        if (!in_array($type, self::TYPES, true)) {
            throw new StagingException("Tipo di campo non valido: '{$type}'. Tipi ammessi: " . implode(', ', self::TYPES) . '.');
        }
        $out = ['name' => $name, 'type' => $type];
        if (isset($def['description'])) $out['description'] = mb_substr((string) $def['description'], 0, 300);
        $section = self::section($def['section'] ?? null);
        if ($section !== '') $out['section'] = $section;
        if ($type === 'array') {
            $of = [];
            foreach ((array) ($def['of'] ?? []) as $child) {
                $child = self::validDef((array) $child, $collection);
                if (self::defPosition($of, $child['name']) !== null) {
                    throw new StagingException("Il campo «{$child['name']}» compare due volte in «{$name}».");
                }
                $of[] = $child;
            }
            $out['of'] = $of;
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
    private static function arrayNames(array $path): array
    {
        $names = [];
        for ($i = 0; $i < count($path); $i += 2) $names[] = (string) $path[$i];
        return $names;
    }

    private static function schemaAt(array $model, string $collection, array $arrayNames): array
    {
        if (!isset($model[$collection]) || !is_array($model[$collection])) {
            throw new StagingException("La raccolta «{$collection}» non esiste.");
        }
        $schema = $model[$collection];
        foreach ($arrayNames as $name) {
            $def = self::fieldDef($schema, $name);
            if ($def['type'] !== 'array') throw new StagingException("«{$name}» non è un elenco.");
            $schema = $def['of'] ?? [];
        }
        return $schema;
    }

    private static function &schemaRef(array &$model, string $collection, array $arrayNames): array
    {
        if (!isset($model[$collection]) || !is_array($model[$collection])) {
            throw new StagingException("La raccolta «{$collection}» non esiste.");
        }
        $schema = &$model[$collection];
        foreach ($arrayNames as $name) {
            $pos = self::defPosition($schema, $name);
            if ($pos === null || ($schema[$pos]['type'] ?? null) !== 'array') {
                throw new StagingException("«{$name}» non è un elenco di «{$collection}».");
            }
            if (!isset($schema[$pos]['of'])) $schema[$pos]['of'] = [];
            $schema = &$schema[$pos]['of'];
        }
        return $schema;
    }

    private static function fieldDef(array $schema, string $name): array
    {
        $pos = self::defPosition($schema, $name);
        if ($pos === null) {
            throw new StagingException("Il campo «{$name}» non esiste nello schema.");
        }
        return $schema[$pos];
    }

    private static function defPosition(array $schema, string $name): ?int
    {
        foreach (array_values($schema) as $i => $def) {
            if (($def['name'] ?? null) === $name) return $i;
        }
        return null;
    }

    /** $setField: i valori di kris_site (la favicon) si possono cambiare, il resto delle raccolte kris_ no. */
    private static function &entity(array &$data, string $key, int $id, array $path, bool $setField = false): array
    {
        if (!($setField && $key === 'kris_site' && !$path)) {
            self::assertUserCollection($key);
        }
        $found = null;
        foreach ($data as $i => $root) {
            if (($root['name'] ?? null) === $key && (int) ($root['id'] ?? -1) === $id) { $found = $i; break; }
        }
        if ($found === null) {
            throw new StagingException("Il contenuto «{$key}» con id {$id} non esiste.");
        }
        $cur = &$data[$found];
        for ($i = 0; $i < count($path); $i += 2) {
            $index = self::fieldIndex($cur, (string) $path[$i]);
            $items = $index === null ? [] : $cur['data'][$index]['value'];
            $pos = self::itemPosition(is_array($items) ? $items : [], (int) $path[$i + 1]);
            if ($pos === null) {
                throw new StagingException("L'elemento {$path[$i]}/{$path[$i + 1]} non esiste.");
            }
            $cur = &$cur['data'][$index]['value'][$pos];
        }
        return $cur;
    }

    /** Chiama $fn su ogni entita al livello indicato: radici della raccolta, o figli dentro gli elenchi. */
    private static function eachEntity(array &$data, string $collection, array $arrayNames, callable $fn): void
    {
        foreach ($data as &$root) {
            if (($root['name'] ?? null) === $collection) self::eachIn($root, $arrayNames, $fn);
        }
    }

    private static function eachIn(array &$entity, array $arrayNames, callable $fn): void
    {
        if (!$arrayNames) {
            $fn($entity);
            return;
        }
        $index = self::fieldIndex($entity, (string) $arrayNames[0]);
        if ($index === null || !is_array($entity['data'][$index]['value'])) return;
        foreach ($entity['data'][$index]['value'] as &$child) {
            self::eachIn($child, array_slice($arrayNames, 1), $fn);
        }
    }

    private static function fieldIndex(array $entity, string $name): ?int
    {
        foreach ($entity['data'] ?? [] as $i => $field) {
            if (($field['name'] ?? null) === $name) return $i;
        }
        return null;
    }

    private static function rootPosition(array $data, string $key, int $id): ?int
    {
        foreach (array_values($data) as $i => $root) {
            if (($root['name'] ?? null) === $key && (int) ($root['id'] ?? -1) === $id) return $i;
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

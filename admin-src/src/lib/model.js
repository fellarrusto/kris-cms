/**
 * Il formato 2 dei dati di Kris (docs/2.0/specifica.md, 2) come lo legge l'editor:
 * tipi, contenuti, elementi degli elenchi, campi. Funzioni pure: lo stato sta nello store del sito.
 */

export const FIELD_TYPES = {
  text: 'Testo',
  richtext: 'Testo formattato',
  media: 'Immagine o file',
  boolean: 'Interruttore',
  select: 'Scelta',
  list: 'Elenco',
}

/** Tipi di Kris (kris_) e dei plugin (plg_): schema fisso, sezioni dedicate. */
export const isReserved = (name) => typeof name === 'string' && (name.startsWith('kris_') || name.startsWith('plg_'))

export const RESERVED_VIEWS = {
  kris_site: { label: 'Proprietà del sito', icon: 'globe' },
  kris_legal: { label: 'Privacy e cookie', icon: 'shield' },
  kris_notfound: { label: 'Pagina 404', icon: 'notfound' },
  kris_wip: { label: 'Lavori in corso', icon: 'wip' },
}

export const humanize = (name) => {
  const s = String(name || '').replace(/_/g, ' ')
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/** I tipi del sito nell'ordine dell'editor, senza quelli riservati. */
export function userTypes(schema) {
  return Object.entries(schema?.types || {})
    .filter(([name, t]) => t && !isReserved(name) && !t.reserved)
    .map(([name, t]) => ({ name, ...t }))
}

export const typeOf = (schema, name) => schema?.types?.[name] || null
export const typeLabel = (schema, name) => (typeOf(schema, name)?.label || '').trim() || RESERVED_VIEWS[name]?.label || humanize(name)
export const typeDescription = (schema, name) => (typeOf(schema, name)?.description || '').trim()
export const isSingle = (schema, name) => typeOf(schema, name)?.single === true
export const isNewestFirst = (schema, name) => typeOf(schema, name)?.newest_first === true
export const isHidden = (item) => item?.hidden === true

export const items = (content, type) => (content || []).filter((i) => i.type === type)
export const item = (content, type, id) => (content || []).find((i) => i.type === type && Number(i.id) === Number(id)) || null

/** Un contenuto o un elemento dei suoi elenchi: path alterna nome dell'elenco e id. */
export function node(content, type, id, path = []) {
  let current = item(content, type, id)
  for (let i = 0; current && i < path.length; i += 2) {
    if (path[i + 1] === undefined) return null
    const list = current.values?.[path[i]]
    current = Array.isArray(list) ? list.find((c) => Number(c?.id) === Number(path[i + 1])) || null : null
  }
  return current
}

export const listNames = (path = []) => path.filter((_, i) => i % 2 === 0).map(String)

/** I campi a un livello: quelli del tipo, o quelli degli elementi degli elenchi nominati. */
export function fields(schema, type, lists = []) {
  let defs = typeOf(schema, type)?.fields || []
  for (const list of lists) {
    const def = defs.find((f) => f?.name === list)
    if (def?.type !== 'list') return []
    defs = def.of || []
  }
  return defs.filter((f) => f && typeof f.name === 'string')
}

export const fieldLabel = (def) => (def?.description || '').trim() || humanize(def?.name)

/** Il testo di un valore nella lingua (o il primo non vuoto), '' se non è testo. */
export function text(value, lang) {
  if (Array.isArray(value)) return ''
  if (value && typeof value === 'object') {
    const v = typeof value[lang] === 'string' && value[lang].trim() !== '' ? value[lang] : Object.values(value).find((x) => typeof x === 'string' && x.trim() !== '')
    value = v ?? ''
  }
  if (typeof value !== 'string') return ''
  const el = document.createElement('div')
  el.innerHTML = value
  return (el.textContent || '').trim()
}

/** Titolo leggibile di un contenuto o di un elemento. */
export function title(n, defs = [], lang) {
  if (!n) return 'Contenuto non disponibile'
  const values = n.values || {}
  const types = Object.fromEntries(defs.map((f) => [f.name, f.type]))
  for (const preferred of ['title', 'page_title', 'titolo', 'name', 'nome', 'meta_title', null]) {
    for (const [name, value] of Object.entries(values)) {
      if (preferred !== null && name !== preferred) continue
      if (types[name] && !['text', 'richtext'].includes(types[name])) continue
      const t = text(value, lang)
      if (t) return t.slice(0, 85)
    }
  }
  return `Contenuto #${n.id ?? 0}`
}

/** Una riga in più sotto il titolo: il primo altro testo o scelta compilati. */
export function subtitle(n, defs, lang, heading) {
  for (const def of defs) {
    if (!['text', 'select'].includes(def.type)) continue
    const value = n.values?.[def.name]
    const t = Array.isArray(value) ? value.join(', ') : text(value, lang)
    if (t && t.slice(0, 85) !== heading) return t.length > 90 ? t.slice(0, 89) + '…' : t
  }
  return ''
}

/** La prima immagine di un contenuto, per la miniatura. */
export function thumb(n, defs) {
  for (const def of defs) {
    if (def.type !== 'media') continue
    const value = n.values?.[def.name]
    const url = value && typeof value === 'object' ? Object.values(value).find((v) => typeof v === 'string' && v) : value
    if (typeof url === 'string' && url && /\.(jpe?g|png|gif|webp|avif|svg)$/i.test(url)) return url
  }
  return ''
}

/** I campi divisi per sezione: quelli senza sezione in testa, poi le sezioni nell'ordine di comparsa. */
export function sections(defs) {
  const groups = new Map([['', []]])
  defs.forEach((f, index) => {
    const key = typeof f.section === 'string' ? f.section : ''
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key).push({ ...f, index })
  })
  return [...groups].filter(([, list]) => list.length).map(([section, list]) => ({ section, fields: list }))
}

const empty = (v) => v === null || v === undefined || v === '' || v === false || (Array.isArray(v) && v.length === 0)
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b) || (empty(a) && empty(b))

/**
 * Le operazioni set_field che portano i valori da before ad after, come EditorChanges::fieldOps:
 * un campo per lingua cambia una lingua per volta, gli elenchi si cambiano con le loro operazioni.
 */
export function fieldOps(key, id, path, defs, before, after, languages) {
  const base = { op: 'set_field', key, id: Number(id), ...(path.length ? { path } : {}) }
  const ops = []
  for (const def of defs) {
    if (def.type === 'list' || !(def.name in after)) continue
    if (!def.localized) {
      if (!same(before[def.name], after[def.name])) ops.push({ ...base, field: def.name, value: after[def.name] })
      continue
    }
    const o = before[def.name] && typeof before[def.name] === 'object' ? before[def.name] : {}
    const n = after[def.name] && typeof after[def.name] === 'object' ? after[def.name] : {}
    for (const lang of languages) {
      if (lang in n && !same(o[lang], n[lang])) ops.push({ ...base, field: def.name, lang, value: n[lang] })
    }
  }
  return ops
}

/** Gli spostamenti [id, posizione] che portano un ordine nell'altro, come EditorChanges::moves. */
export function moves(before, after) {
  const current = before.map(Number)
  const target = []
  for (const id of after.map(Number)) if (current.includes(id) && !target.includes(id)) target.push(id)
  const out = []
  target.forEach((id, to) => {
    if (current[to] === id) return
    current.splice(current.indexOf(id), 1)
    current.splice(to, 0, id)
    out.push([id, to])
  })
  return out
}

/** Valore vuoto di un campo nuovo, come Operations::emptyValue. */
export function emptyValue(def, languages) {
  const one = def.type === 'boolean' ? false : def.type === 'select' && def.multiple ? [] : ''
  if (def.type === 'list') return []
  return def.localized ? Object.fromEntries(languages.map((l) => [l, one])) : one
}

export const isEmpty = empty

/** Indirizzo da mostrare per un file del sito (l'editor sta in /kris-admin/). */
export function mediaSrc(url, stagedNames = new Set()) {
  if (!url) return ''
  const staged = /^assets\/uploads\/([A-Za-z0-9._-]+)$/.exec(url)
  if (staged && stagedNames.has(staged[1])) return `../index.php?kris_preview_asset=uploads/${encodeURIComponent(staged[1])}&kris_preview=1`
  if (/^https?:\/\//i.test(url)) return url
  if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(url)) return ''
  return url.startsWith('/') || url.startsWith('../') ? url : '../' + url
}

export const mediaKind = (name) => (/\.pdf$/i.test(name) ? 'pdf' : /\.(mp4|webm)$/i.test(name) ? 'video' : 'image')

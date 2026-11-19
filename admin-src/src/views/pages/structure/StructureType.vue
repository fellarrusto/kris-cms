<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import FieldRow from '@/views/pages/structure/components/FieldRow.vue'
import { useDirty } from '@/components/dirty'
import SiteService from '@/services/SiteService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { isNewestFirst, isSingle, typeDescription } from '@/lib/model'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()
const { setDirty } = useDirty()

const type = computed(() => String(route.params.type))
const count = computed(() => site.items(type.value).length)
const pages = computed(() => Object.entries(site.schema.pages || {}).filter(([, p]) => p?.type === type.value))
const RESERVED_NAMES = ['id', 'language', 'group', 'path', 'f', 'order', 'langs']

// La struttura in modifica: sezioni (la prima senza nome) con i loro campi
let counter = 0
const keyed = (f) => ({ ...JSON.parse(JSON.stringify(f)), _key: `k${counter++}`, ...(f.type === 'list' ? { of: (f.of || []).map(keyed) } : {}) })
const groups = ref([])
const saved = ref('')
function reset() {
  const map = new Map([['', []]])
  for (const f of site.fieldsOf(type.value)) {
    const s = typeof f.section === 'string' ? f.section : ''
    if (!map.has(s)) map.set(s, [])
    map.get(s).push(keyed(f))
  }
  groups.value = [...map].map(([name, fields], i) => ({ key: i ? `s${counter++}` : 'none', name, fields }))
  saved.value = JSON.stringify(serialize())
  impact.value = null
}
const impact = ref(null)

function strip(f) {
  const { _key, _touchedLocalized, ...rest } = f
  void _key
  void _touchedLocalized
  return rest.type === 'list' ? { ...rest, of: (rest.of || []).map(strip) } : rest
}
function serialize() {
  return groups.value.flatMap((g) => g.fields.map((f) => ({ ...strip(f), section: g.key === 'none' ? '' : g.name.trim() })))
}
const dirty = computed(() => JSON.stringify(serialize()) !== saved.value)
watch(dirty, (d) => setDirty(d))
onBeforeUnmount(() => setDirty(false))
let initialized = false
watch(
  () => site.schema.types?.[type.value],
  (t) => {
    if (!t) return router.replace({ name: 'structure' })
    // Al primo caricamento sempre; poi solo se non si sta modificando
    if (!initialized || !dirty.value) reset()
    initialized = true
  },
  { immediate: true }
)

// Controlli prima di mandare: nomi validi e diversi, opzioni presenti
const errors = computed(() => {
  const out = {}
  const check = (list) => {
    const seen = new Set()
    for (const f of list) {
      if (!/^[a-z][a-z0-9_]*$/.test(f.name || '')) out[f._key] = 'Nome tecnico: lettere minuscole, numeri e _, iniziando con una lettera.'
      else if (seen.has(f.name)) out[f._key] = `Il nome «${f.name}» è già usato qui.`
      else if (RESERVED_NAMES.includes(f.name)) out[f._key] = `«${f.name}» è un nome riservato: usane un altro.`
      else if (f.type === 'select' && !(f.options || []).length) out[f._key] = 'Una scelta ha bisogno di almeno un\'opzione.'
      else if (f.type === 'select' && f.options.some((o) => o.includes(','))) out[f._key] = 'Le opzioni non possono contenere virgole.'
      seen.add(f.name)
      if (f.type === 'list') check(f.of || [])
    }
  }
  check(groups.value.flatMap((g) => g.fields))
  groups.value.forEach((g) => {
    if (g.key !== 'none' && g.fields.length && !g.name.trim()) out[g.key] = 'Dai un nome alla sezione.'
  })
  return out
})
const errorCount = computed(() => Object.keys(errors.value).length)

const sectionOptions = computed(() => groups.value.map((g) => ({ key: g.key, name: g.name })))
function addField(group = groups.value[0]) {
  group.fields.push({ _key: `k${counter++}`, name: '', type: 'text', localized: true, description: '' })
}
function addSection() {
  groups.value.push({ key: `s${counter++}`, name: '', fields: [] })
}
function removeSection(index) {
  const [g] = groups.value.splice(index, 1)
  groups.value[0].fields.push(...g.fields)
}
async function removeField(group, index) {
  const f = group.fields[index]
  if (f.name && !(await ui.confirm({ title: 'Togliere questo campo?', text: `«${f.name}» sparisce dalla struttura proposta. Prima di salvare vedrai se ci sono testi che andrebbero persi.`, confirmLabel: 'Togli campo' }))) return
  group.fields.splice(index, 1)
}
// Su e giù fra i campi, passando da una sezione all'altra ai bordi
function moveField(gi, index, delta) {
  const group = groups.value[gi]
  const target = index + delta
  if (target >= 0 && target < group.fields.length) {
    const [f] = group.fields.splice(index, 1)
    group.fields.splice(target, 0, f)
    return
  }
  const next = groups.value[gi + delta]
  if (!next) return
  const [f] = group.fields.splice(index, 1)
  if (delta < 0) next.fields.push(f)
  else next.fields.unshift(f)
}
function setSection(gi, index, key) {
  const [f] = groups.value[gi].fields.splice(index, 1)
  groups.value.find((g) => g.key === key).fields.push(f)
}
const isFirst = (gi, i) => gi === 0 && i === 0
const isLast = (gi, i) => gi === groups.value.length - 1 && i === groups.value[gi].fields.length - 1

const saving = ref(false)
async function save(confirm = false) {
  if (errorCount.value) return ui.toast('Correggi i campi segnati prima di salvare.')
  saving.value = true
  try {
    const r = await SiteService.saveStructure(type.value, serialize(), confirm)
    await site.load()
    saved.value = JSON.stringify(serialize())
    reset()
    ui.toast(r.changed ? 'Struttura salvata.' : 'La struttura era già così.')
  } catch (e) {
    if (e.status === 409 && e.data?.impact) impact.value = e.data.impact
    else ui.toast(e.message)
  }
  saving.value = false
}

// Nome, posizione, contenuto unico, nuovi in cima: operazioni sul tipo
const info = ref({ label: '', description: '', single: false, position: 0 })
watch(
  () => [site.schema.types?.[type.value], site.userTypes.length],
  () => {
    info.value = {
      label: site.type(type.value)?.label || '',
      description: typeDescription(site.schema, type.value),
      single: isSingle(site.schema, type.value),
      position: site.userTypes.findIndex((t) => t.name === type.value),
    }
  },
  { immediate: true }
)
async function saveInfo() {
  const ops = [{ op: 'set_collection_info', collection: type.value, label: info.value.label, description: info.value.description, single: info.value.single }]
  if (info.value.position !== site.userTypes.findIndex((t) => t.name === type.value)) ops.push({ op: 'move_collection', collection: type.value, to: Number(info.value.position) })
  try {
    await site.apply(ops)
    ui.toast('Nome e posizione salvati.')
  } catch (e) {
    ui.toast(e.message)
  }
}
async function toggleNewest() {
  const enabled = !isNewestFirst(site.schema, type.value)
  try {
    await site.apply([{ op: 'set_post_collection', collection: type.value, enabled }])
    ui.toast(enabled ? 'I nuovi contenuti entrano in cima.' : 'I nuovi contenuti entrano in fondo.')
  } catch (e) {
    ui.toast(e.message)
  }
}
async function removeType() {
  const ok = await ui.confirm({
    title: `Eliminare «${site.label(type.value)}»?`,
    text: `Verranno eliminati il tipo e ${count.value} ${count.value === 1 ? 'contenuto' : 'contenuti'}, con i loro elementi${pages.value.length ? ' e le pagine che lo rendono' : ''}. Si annulla solo ripristinando una copia di sicurezza.`,
    confirmLabel: 'Elimina tipo',
  })
  if (!ok) return
  try {
    setDirty(false)
    await site.apply([{ op: 'remove_collection', name: type.value }])
    ui.toast('Tipo eliminato.')
    router.push({ name: 'structure' })
  } catch (e) {
    ui.toast(e.message)
  }
}
</script>

<template>
  <div class="container">
    <div v-if="session.isStaging" class="notice notice-warning"><strong>Solo sul sito pubblico.</strong><p>La struttura si cambia sul sito pubblico: passa a «Sito pubblico» per modificarla.</p></div>
    <div class="page-head">
      <div>
        <p class="kicker"><router-link :to="{ name: 'structure' }">Struttura</router-link></p>
        <h1>{{ site.label(type) }}</h1>
        <p class="sub mono">{{ type }} · {{ serialize().length }} {{ serialize().length === 1 ? 'campo' : 'campi' }} · {{ count }} {{ count === 1 ? 'contenuto' : 'contenuti' }}</p>
      </div>
      <router-link class="btn btn-white" :to="{ name: 'collection', params: { type } }">Apri i contenuti</router-link>
    </div>

    <div v-if="impact" class="box" style="box-shadow: inset 0 0 0 1.5px var(--warn-line)" role="alert">
      <h2>Prima di salvare</h2>
      <p class="sub" style="font-size: 14px; margin: 4px 0 12px">Queste modifiche tolgono o ricreano campi che contengono già dei testi: salvando, quei testi vengono cancellati.</p>
      <table class="impact-table">
        <thead><tr><th scope="col">Campo</th><th scope="col">Cosa succede</th><th scope="col">Cosa si perde</th></tr></thead>
        <tbody>
          <tr v-for="row in impact" :key="row.path">
            <td class="mono">{{ row.path }}</td>
            <td class="reason">{{ row.reason }}</td>
            <td><strong>{{ row.count }}</strong> {{ row.count === 1 ? 'contenuto compilato' : 'contenuti compilati' }}<div class="muted"><template v-for="s in row.samples" :key="s">«{{ s.length > 80 ? s.slice(0, 79) + '…' : s }}»<br /></template></div></td>
          </tr>
        </tbody>
      </table>
      <div class="bar" style="margin-top: 16px">
        <button type="button" class="btn btn-white" @click="impact = null">Torna alla struttura</button>
        <button type="button" class="btn btn-danger" :disabled="saving" @click="save(true)">Salva e cancella quei testi</button>
      </div>
    </div>

    <div class="structure-cols">
      <form class="container" @submit.prevent="save(false)">
        <div class="sf-container">
          <section v-for="(g, gi) in groups" :key="g.key" class="sf-group" :class="{ 'sf-section-group': g.key !== 'none' }">
            <p v-if="g.key === 'none'" class="sf-group-label">Campi senza sezione</p>
            <div v-else class="sf-group-header">
              <label class="field-label">Nome della sezione<input v-model="g.name" class="input" type="text" maxlength="60" placeholder="Es. Meta, Contatti" :aria-invalid="String(!!errors[g.key])" /></label>
              <button type="button" class="btn btn-white btn-small" @click="removeSection(gi)">Togli sezione</button>
            </div>
            <p v-if="errors[g.key]" class="field-error">{{ errors[g.key] }}</p>
            <FieldRow
              v-for="(f, i) in g.fields"
              :key="f._key"
              :field="f"
              :sections="sectionOptions"
              :section-key="g.key"
              :first="isFirst(gi, i)"
              :last="isLast(gi, i)"
              :errors="errors"
              @move="moveField(gi, i, $event)"
              @remove="removeField(g, i)"
              @section="setSection(gi, i, $event)"
            />
            <p v-if="!g.fields.length" class="sf-drop-hint">{{ g.key === 'none' ? 'Nessun campo fuori dalle sezioni.' : 'Sposta qui i campi con il menu «Sezione» o le frecce. Una sezione vuota non si salva.' }}</p>
            <button v-if="g.key !== 'none'" type="button" class="btn btn-white btn-small" style="align-self: flex-start" @click="addField(g)"><KIcon name="plus" />Campo in questa sezione</button>
          </section>
        </div>
        <div class="sf-actions">
          <button type="button" class="btn btn-white" @click="addField()"><KIcon name="plus" />Aggiungi campo</button>
          <button type="button" class="btn btn-white" @click="addSection"><KIcon name="plus" />Aggiungi sezione</button>
        </div>
        <div class="savebar" :class="{ 'is-dirty': dirty }" role="status">
          <div class="savebar-text">
            <strong>{{ dirty ? (errorCount ? `Modifiche non salvate · ${errorCount} ${errorCount === 1 ? 'errore' : 'errori'} da correggere` : 'Modifiche non salvate') : 'La struttura è salvata' }}</strong>
            <small>Togliere un campo o cambiarne il tipo può cancellare testi già scritti: prima vedrai cosa si perde.</small>
          </div>
          <button v-if="dirty" type="button" class="btn" @click="reset">Annulla modifiche</button>
          <button type="submit" class="btn" data-save-button :disabled="!dirty || saving || session.isStaging || session.locked">Salva struttura</button>
        </div>
      </form>

      <aside class="structure-side">
        <form class="box collection-info-form" @submit.prevent="saveInfo">
          <h2>Nome e posizione</h2>
          <label class="field-label">Nome visibile<input v-model="info.label" class="input" type="text" maxlength="60" :placeholder="type" /></label>
          <label class="field-label">Descrizione<input v-model="info.description" class="input" type="text" maxlength="200" placeholder="Una riga su cosa contiene" /></label>
          <label class="field-label">Posizione fra le raccolte
            <select v-model.number="info.position" class="input"><option v-for="(t, i) in site.userTypes" :key="t.name" :value="i">{{ i + 1 }}</option></select>
          </label>
          <label class="check-row">
            <input v-model="info.single" type="checkbox" :disabled="count > 1" />
            <span><strong>Contenuto unico</strong><small>{{ count > 1 ? `Possibile solo con un contenuto: questa raccolta ne ha ${count}.` : 'Si apre direttamente la modifica, senza lista, nuovo contenuto né eliminazione.' }}</small></span>
          </label>
          <button class="btn btn-white btn-small" :disabled="session.isStaging || session.locked">Salva</button>
        </form>
        <div v-if="!isSingle(site.schema, type)" class="box">
          <h2>I nuovi in cima</h2>
          <p class="muted" style="margin: 4px 0 12px">Per articoli, notizie o eventi: i nuovi contenuti entrano in cima e la raccolta ha la sua voce nella barra laterale.</p>
          <button type="button" class="btn btn-white btn-small" :disabled="session.isStaging || session.locked" @click="toggleNewest">{{ isNewestFirst(site.schema, type) ? 'Disattiva' : 'Attiva' }}</button>
        </div>
        <div class="box">
          <h2>Pagine</h2>
          <p v-if="pages.length" class="mono" style="margin-top: 8px; line-height: 1.8">
            <template v-for="[name, p] in pages" :key="name">/{{ name }} → template/{{ p.template || name }}.html{{ p.item === undefined ? ' (una per contenuto)' : '' }}<br /></template>
          </p>
          <p v-else class="muted" style="margin-top: 6px">Nessuna pagina del sito rende questo tipo da sola: lo usano i template delle altre pagine.</p>
          <router-link class="text-link" style="margin-top: 8px" :to="{ name: 'addresses' }">Indirizzi del sito</router-link>
        </div>
        <div class="danger-zone">
          <h2>Elimina il tipo</h2>
          <p>Con i suoi {{ count }} {{ count === 1 ? 'contenuto' : 'contenuti' }}{{ pages.length ? ' e le sue pagine' : '' }}. Si annulla solo ripristinando una copia di sicurezza.</p>
          <button type="button" class="btn btn-danger btn-small" :disabled="session.isStaging || session.locked" @click="removeType">Elimina tipo</button>
        </div>
      </aside>
    </div>
  </div>
</template>

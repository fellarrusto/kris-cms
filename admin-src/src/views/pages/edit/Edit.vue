<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import KSwitch from '@/components/KSwitch.vue'
import SortableList from '@/components/SortableList.vue'
import FieldInput from '@/views/pages/edit/components/FieldInput.vue'
import PluginCookies from '@/components/PluginCookies.vue'
import { useDirty } from '@/components/dirty'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import * as model from '@/lib/model'

/**
 * Modifica di un contenuto. Con fixedType è una sezione di Kris dentro una schermata a schede
 * (Impostazioni, Pagine di sistema): contenuto 0 del tipo, senza intestazione propria.
 */
const props = defineProps({ fixedType: { type: String, default: '' } })
const embedded = computed(() => !!props.fixedType)
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()
const { setDirty, setBusy } = useDirty()

const INTRO = {
  kris_site:
    'Icona, nome, titolo e condivisione del sito, codici di verifica dei motori di ricerca. Kris li aggiunge a ogni pagina solo dove il template non li ha già.',
  kris_legal: 'Dati del titolare, informative e testi dell’avviso sui cookie. Il sito li mostra dove i suoi template li usano.',
  kris_notfound:
    'Quello che vede chi apre un indirizzo che non esiste. Se il tema ha un suo 404.html usa questi testi, altrimenti li usa la pagina di serie di Kris.',
  kris_wip:
    'Con l’interruttore acceso i visitatori vedono solo questa pagina (stato 503: i motori di ricerca tornano più tardi). Tu, con l’editor aperto, continui a vedere il sito: per vedere la pagina usa «Vedi la pagina di lavorazione», o apri il sito in una finestra anonima.',
}

const type = computed(() => props.fixedType || String(route.params.type))
const id = computed(() => (props.fixedType ? 0 : Number(route.params.id)))
const path = computed(() =>
  props.fixedType ? [] : (Array.isArray(route.params.path) ? route.params.path : route.params.path ? [route.params.path] : []).map(String)
)
const reserved = computed(() => model.isReserved(type.value))
const languages = computed(() => session.languages)
const fields = computed(() => site.fieldsOf(type.value, model.listNames(path.value)))
const node = computed(() => (path.value.length % 2 === 0 ? model.node(site.content, type.value, id.value, path.value) : null))
const root = computed(() => model.item(site.content, type.value, id.value))
const stagedNames = computed(() => new Set(session.data?.staged_uploads || []))
const accept = computed(() => (session.data?.uploads?.accept || []).map((e) => '.' + e).join(','))
const blockedHere = computed(() => session.isStaging && type.value === 'kris_legal')

// Valori in modifica: una copia, confrontata con quelli salvati
const original = ref({})
const values = ref({})
function reset() {
  original.value = JSON.parse(JSON.stringify(node.value?.values || {}))
  values.value = JSON.parse(JSON.stringify(original.value))
}
const ops = computed(() =>
  node.value ? model.fieldOps(type.value, id.value, path.value, fields.value, original.value, values.value, languages.value) : []
)
const dirty = computed(() => ops.value.length > 0)
watch(dirty, (d) => setDirty(d), { immediate: true })
watch(
  node,
  (n, old) => {
    // Dopo un salvataggio o un'azione su un elenco i valori salvati cambiano: si riparte da lì,
    // tenendo i campi che si stanno modificando
    if (!old || n?.id !== old?.id || !dirty.value) reset()
    else original.value = JSON.parse(JSON.stringify(n?.values || {}))
  },
  { immediate: true }
)

// L'indice apre la sezione e ci porta, senza cambiare l'indirizzo
function openAndGo(targetId) {
  const el = document.getElementById(targetId)
  if (!el) return
  if (el.tagName === 'DETAILS') el.open = true
  el.closest('details')?.setAttribute('open', '')
  el.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
onBeforeUnmount(() => setDirty(false))

const lang = ref(languages.value[0])
const localized = computed(() => fields.value.filter((f) => f.type !== 'list' && f.localized))
const missing = computed(() =>
  Object.fromEntries(
    languages.value
      .slice(1)
      .map((l) => [
        l,
        localized.value.filter(
          (f) =>
            ['text', 'richtext'].includes(f.type) &&
            String(values.value[f.name]?.[languages.value[0]] || '').trim() &&
            !String(values.value[f.name]?.[l] || '').trim()
        ).length,
      ])
  )
)

const sections = computed(() => model.sections(fields.value))
const openSection = computed(() => (sections.value[0]?.section !== '' ? 0 : 1))

const title = computed(() => {
  if (!path.value.length && (reserved.value || model.isSingle(site.schema, type.value)))
    return reserved.value ? model.RESERVED_VIEWS[type.value]?.label : site.label(type.value)
  return model.title(node.value, fields.value, languages.value[0])
})
const kicker = computed(() => {
  if (path.value.length) {
    const listDef = site
      .fieldsOf(type.value, model.listNames(path.value.slice(0, -2)))
      .find((f) => f.name === path.value[path.value.length - 2])
    return `${site.label(type.value)} / ${listDef ? model.fieldLabel(listDef) : path.value[path.value.length - 2]}`
  }
  if (reserved.value) return 'Sito'
  return model.isSingle(site.schema, type.value)
    ? Object.values(site.schema.pages || {}).some((p) => p?.type === type.value)
      ? 'Pagine'
      : 'Componenti'
    : site.label(type.value)
})
const back = computed(() => {
  if (path.value.length)
    return {
      name: 'edit',
      params: { type: type.value, id: id.value, path: path.value.slice(0, -2) },
      hash: `#list-${path.value[path.value.length - 2]}`,
    }
  if (reserved.value || model.isSingle(site.schema, type.value)) return { name: 'dashboard' }
  return { name: 'collection', params: { type: type.value } }
})
const crumbs = computed(() => {
  const out = []
  for (let i = 0; i < path.value.length; i += 2) {
    const p = path.value.slice(0, i + 2)
    out.push({
      label: model.title(
        model.node(site.content, type.value, id.value, p),
        site.fieldsOf(type.value, model.listNames(p)),
        languages.value[0]
      ),
      to: { name: 'edit', params: { type: type.value, id: id.value, path: p } },
    })
  }
  return out
})
const canHide = computed(() => node.value && !reserved.value && (path.value.length || site.items(type.value).length > 1))

const saving = ref(false)
async function run(list, message) {
  if (saving.value || !list.length) return null
  saving.value = true
  setBusy(true)
  try {
    const result = await site.apply(list)
    ui.toast([message, ...(result.warnings || [])].filter(Boolean).join(' '))
    return result
  } catch (e) {
    ui.toast(e.message)
    return null
  } finally {
    saving.value = false
    setBusy(false)
  }
}

async function save() {
  const list = ops.value
  if (!list.length) return
  const result = await run(
    list,
    session.isStaging
      ? 'Modifiche messe in attesa: il sito pubblico non cambia finché non le pubblichi.'
      : 'Salvato: è già visibile sul sito.'
  )
  if (result) reset()
}
async function discard() {
  if (
    await ui.confirm({
      title: 'Annullare le modifiche?',
      text: 'Torni all’ultima versione salvata: le modifiche di questa pagina vanno perse.',
      confirmLabel: 'Annulla modifiche',
    })
  )
    reset()
}
function onKey(event) {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
    event.preventDefault()
    save()
  }
}
onMounted(() => window.addEventListener('keydown', onKey))
onBeforeUnmount(() => window.removeEventListener('keydown', onKey))

// Elenchi: aggiunte, eliminazioni, ordine e visibilità si salvano subito
const listItems = (def) =>
  (Array.isArray(node.value?.values?.[def.name]) ? node.value.values[def.name] : []).map((c) => ({
    id: Number(c.id),
    hidden: c.hidden === true,
    title: model.title(c, def.of || [], languages.value[0]),
  }))
const listBase = (def) => ({ key: type.value, id: id.value, path: path.value, array: def.name })

async function addItem(def) {
  if (dirty.value && !(await confirmSaveFirst())) return
  const result = await run([{ op: 'add_item', ...listBase(def) }], 'Elemento aggiunto.')
  if (result)
    router.push({
      name: 'edit',
      params: { type: type.value, id: id.value, path: [...path.value, def.name, String(result.done[0].assigned_id)] },
    })
}
async function removeItem(def, item) {
  if (
    !(await ui.confirm({
      title: 'Eliminare questo elemento?',
      text: `«${item.title}» e i suoi elementi verranno eliminati${session.isStaging ? ' quando pubblichi' : ' subito dal sito'}.`,
      confirmLabel: 'Elimina',
    }))
  )
    return
  run([{ op: 'remove_item', ...listBase(def), item_id: item.id }], 'Elemento eliminato.')
}
function reorderItems(def, ids) {
  const list = model
    .moves(
      listItems(def).map((i) => i.id),
      ids
    )
    .map(([itemId, to]) => ({ op: 'move_item', ...listBase(def), item_id: itemId, to }))
  run(list, session.isStaging ? 'Ordine messo in attesa.' : 'Ordine salvato.')
}
function setItemVisible(def, item, visible) {
  run(
    [{ op: 'set_hidden', key: type.value, id: id.value, path: [...path.value, def.name, item.id], hidden: !visible }],
    visible ? 'Visibile sul sito.' : 'Sospeso: il sito non lo mostra.'
  )
}
function setVisible(visible) {
  run(
    [{ op: 'set_hidden', key: type.value, id: id.value, path: path.value, hidden: !visible }],
    visible ? 'Visibile sul sito.' : 'Sospeso: il sito non lo mostra.'
  )
}
async function confirmSaveFirst() {
  const choice = await ui.confirm({
    title: 'Salvare prima?',
    text: 'Hai modifiche non salvate nei campi di questa pagina.',
    options: [
      { label: 'Annulla', value: null },
      { label: 'Salva e continua', value: 'save', kind: 'btn-primary' },
    ],
  })
  if (choice !== 'save') return false
  await save()
  return !dirty.value
}

const scopeLabel = (def) => (def.type === 'list' ? 'Elenco' : def.localized ? 'Per lingua' : 'Uguale in tutte le lingue')
const valueFor = (def) => (def.localized ? values.value[def.name]?.[lang.value] : values.value[def.name])
function setValue(def, value) {
  if (def.localized) {
    const current =
      values.value[def.name] && typeof values.value[def.name] === 'object' && !Array.isArray(values.value[def.name])
        ? values.value[def.name]
        : {}
    values.value[def.name] = { ...current, [lang.value]: value }
  } else {
    values.value[def.name] = value
  }
}
const isChanged = (def) =>
  JSON.stringify(valueFor(def) ?? '') !==
  JSON.stringify((def.localized ? original.value[def.name]?.[lang.value] : original.value[def.name]) ?? '')
</script>

<template>
  <div :class="embedded ? 'embedded-edit' : 'container'">
    <div class="edit-cols" :class="{ 'no-index': embedded || !node || !fields.length }">
      <component :is="node && fields.length ? 'form' : 'div'" class="panel" @submit.prevent="save">
        <div class="edit-top">
          <div class="edit-head">
            <template v-if="!embedded">
              <router-link class="back" :to="back" aria-label="Torna indietro"><KIcon name="back" /></router-link>
              <div class="edit-head-text">
                <p class="kicker">{{ kicker }}</p>
                <h1>{{ title }}</h1>
              </div>
            </template>
            <div class="edit-head-tools">
              <div v-if="node && localized.length && languages.length > 1" class="lang-switch" role="group" aria-label="Lingua di modifica">
                <button v-for="l in languages" :key="l" type="button" :aria-pressed="String(lang === l)" @click="lang = l">
                  {{ session.languageName(l)
                  }}<span v-if="missing[l]" class="pill pill-danger">{{ missing[l] }} {{ missing[l] === 1 ? 'vuoto' : 'vuoti' }}</span>
                </button>
              </div>
              <a
                v-if="type === 'kris_wip'"
                class="btn btn-white btn-small"
                :href="`${session.data?.site_url || '../'}index.php?kris_wip_preview=1&ln=${lang}`"
                target="_blank"
                rel="noopener"
                ><KIcon name="external" />Vedi la pagina di lavorazione</a
              >
              <KSwitch
                v-if="canHide"
                :model-value="!node.hidden"
                :label="`Visibile sul sito: ${title}`"
                :text="node.hidden ? 'Sospeso' : 'Visibile'"
                :disabled="saving || session.locked"
                @update:model-value="setVisible"
              />
            </div>
          </div>
          <p v-if="INTRO[type] && !path.length" class="sub">{{ INTRO[type] }}</p>
          <nav v-if="path.length && root" class="nested-crumb" aria-label="Contenuto annidato">
            <router-link :to="{ name: 'edit', params: { type, id } }">{{
              model.title(root, site.fieldsOf(type), languages[0])
            }}</router-link>
            <template v-for="c in crumbs" :key="c.label"
              ><span aria-hidden="true">/</span><router-link :to="c.to">{{ c.label }}</router-link></template
            >
          </nav>
          <div v-if="blockedHere" class="notice notice-warning">
            <strong>Solo sul sito pubblico.</strong>
            <p>Privacy e cookie si modificano sul sito pubblico: passa a «Sito pubblico» per cambiarli.</p>
          </div>
        </div>
        <div v-if="!node" class="empty-state">
          <h2>Questo contenuto non è più disponibile.</h2>
          <p>Potrebbe essere stato eliminato. Torna indietro e scegline un altro.</p>
          <router-link class="btn btn-primary" :to="back">Torna indietro</router-link>
        </div>
        <div v-else-if="!fields.length" class="empty-state">
          <h2>Non ci sono campi da compilare.</h2>
          <router-link v-if="!session.isStaging && !reserved" class="btn btn-primary" :to="{ name: 'structure-type', params: { type } }"
            >Scegli i campi in Struttura</router-link
          >
        </div>
        <template v-else>
          <component
            :is="s.section ? 'details' : 'div'"
            v-for="(s, si) in sections"
            :id="`section-${si}`"
            :key="si"
            :class="{ 'field-section': s.section }"
            :open="s.section ? si === openSection : undefined"
          >
            <summary v-if="s.section">
              {{ s.section }} <span class="count">{{ s.fields.length }} {{ s.fields.length === 1 ? 'campo' : 'campi' }}</span>
            </summary>
            <div v-for="def in s.fields" :id="`field-${def.index}`" :key="def.name" class="field">
              <div class="field-head">
                <label
                  v-if="['text', 'select', 'boolean'].includes(def.type) && !(def.type === 'select' && def.multiple)"
                  :for="`f-${def.index}`"
                  >{{ model.fieldLabel(def) }}</label
                >
                <span v-else>{{ model.fieldLabel(def) }}</span>
                <span class="scope">{{ scopeLabel(def) }}</span><span class="field-tech">{{ def.name }}</span>
              </div>

              <div v-if="def.type === 'list'" :id="`list-${def.name}`" class="list-field">
                <SortableList
                  v-if="listItems(def).length"
                  :items="listItems(def)"
                  :disabled="saving || session.locked || blockedHere"
                  @reorder="reorderItems(def, $event)"
                >
                  <template #default="{ item: child }">
                    <button
                      type="button"
                      class="icon-button handle"
                      data-handle
                      :aria-label="`Sposta ${child.title}: trascina, o usa le frecce su e giù`"
                    >
                      <KIcon name="drag" />
                    </button>
                    <router-link class="row-main" :to="{ name: 'edit', params: { type, id, path: [...path, def.name, String(child.id)] } }"
                      ><span class="row-text"
                        ><span class="row-title">{{ child.title }}</span></span
                      ></router-link
                    >
                    <div class="row-actions">
                      <span v-if="child.hidden" class="pill">Sospeso</span>
                      <KSwitch
                        :model-value="!child.hidden"
                        :label="`Visibile sul sito: ${child.title}`"
                        :disabled="saving || session.locked"
                        @update:model-value="setItemVisible(def, child, $event)"
                      />
                      <button
                        type="button"
                        class="icon-button danger"
                        :aria-label="`Elimina ${child.title}`"
                        :disabled="saving || session.locked"
                        @click="removeItem(def, child)"
                      >
                        <KIcon name="trash" />
                      </button>
                    </div>
                  </template>
                </SortableList>
                <p v-else class="muted">Ancora nessun elemento.</p>
                <div class="bar">
                  <button
                    type="button"
                    class="btn btn-white btn-small"
                    :disabled="saving || session.locked || blockedHere"
                    @click="addItem(def)"
                  >
                    <KIcon name="plus" />Aggiungi elemento
                  </button>
                  <span class="muted">Aggiunta, eliminazione e ordine si salvano subito.</span>
                </div>
              </div>
              <FieldInput
                v-else
                :id="`f-${def.index}`"
                :def="def"
                :label="def.localized ? `${model.fieldLabel(def)} · ${session.languageName(lang)}` : model.fieldLabel(def)"
                :model-value="valueFor(def)"
                :changed="isChanged(def)"
                :staged-names="stagedNames"
                :accept="accept"
                @update:model-value="setValue(def, $event)"
              />
            </div>
          </component>

          <div class="savebar" :class="{ 'is-dirty': dirty, 'is-saving': saving }" role="status">
            <div class="savebar-text">
              <strong>{{
                saving
                  ? 'Salvataggio…'
                  : dirty
                    ? ops.length === 1
                      ? '1 modifica non salvata'
                      : `${ops.length} modifiche non salvate`
                    : 'Tutte le modifiche sono salvate'
              }}</strong>
              <small>{{
                session.isStaging
                  ? 'Salvando, diventano modifiche in attesa. Il sito non cambia.'
                  : 'Salvando, le modifiche sono subito visibili sul sito.'
              }}</small>
            </div>
            <button v-if="dirty" type="button" class="btn" :disabled="saving" @click="discard">Annulla modifiche</button>
            <button type="submit" class="btn" data-save-button :disabled="saving || !dirty || session.locked || blockedHere">
              {{ session.isStaging ? 'Salva in attesa' : 'Salva' }}
            </button>
          </div>
        </template>
      </component>
      <nav v-if="node && fields.length" class="index" aria-label="Campi del contenuto">
        <p>In questa pagina</p>
        <template v-for="(s, si) in sections" :key="si">
          <a v-if="s.section" :href="`#section-${si}`" @click.prevent="openAndGo(`section-${si}`)"
            >{{ s.section }} <span class="mono">{{ s.fields.length }}</span></a
          >
          <template v-else
            ><a v-for="def in s.fields" :key="def.name" :href="`#field-${def.index}`" @click.prevent="openAndGo(`field-${def.index}`)">{{
              model.fieldLabel(def)
            }}</a></template
          >
        </template>
        <p class="note">
          {{
            session.isStaging
              ? 'Salvando, le modifiche restano in attesa: il sito cambia quando pubblichi.'
              : 'Salvando, le modifiche sono subito visibili sul sito.'
          }}
        </p>
      </nav>
    </div>
    <PluginCookies v-if="type === 'kris_legal'" style="margin-top: 24px" />
  </div>
</template>

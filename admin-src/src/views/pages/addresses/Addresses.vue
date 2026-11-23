<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import KSwitch from '@/components/KSwitch.vue'
import api from '@/plugins/api'
import SiteService from '@/services/SiteService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { useRoute } from 'vue-router'

/** Le pagine pubbliche, gli indirizzi leggibili e i reindirizzamenti dai vecchi indirizzi. */
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()
const route = useRoute()

// Tre schede: pagine pubbliche, reindirizzamenti, opzioni
const TABS = [
  { key: 'pagine', label: 'Pagine pubbliche', hint: 'Indirizzi e sitemap', icon: 'page' },
  { key: 'reindirizzamenti', label: 'Reindirizzamenti', hint: 'Dai vecchi indirizzi', icon: 'arrow' },
  { key: 'opzioni', label: 'Opzioni', hint: 'Lingua, canonical, robots', icon: 'settings' },
]
const tab = computed(() => (TABS.some((t) => t.key === route.params.tab) ? route.params.tab : 'pagine'))

const loaded = ref(false)
const templates = ref([])
const seo = ref({ auto_language: false, head: true, base_url: '', robots: '' })
const redirects = ref([])
const busy = ref(false)
const readOnly = computed(() => session.isStaging || session.locked)

async function load() {
  try {
    const r = await api.get('pages')
    templates.value = r.templates
    seo.value = {
      auto_language: !!r.seo.auto_language,
      head: r.seo.head !== false,
      base_url: r.seo.base_url || '',
      robots: (r.seo.robots || []).join('\n'),
    }
    redirects.value = Object.entries(r.redirects || {}).map(([from, to]) => ({ from, to }))
  } catch (e) {
    ui.toast(e.message)
  }
  loaded.value = true
}
onMounted(load)

const pages = computed(() =>
  Object.entries(site.schema.pages || {}).map(([name, p]) => {
    const fixed = p.item !== undefined && p.item !== null
    const address = name === 'homepage' ? '/' : `/${name}${fixed && !p.list ? '' : '/…'}`
    return {
      name,
      address,
      type: p.type,
      label: site.type(p.type) ? site.label(p.type) : p.type,
      fixed,
      item: p.item ?? 0,
      template: p.template || name,
      sitemap: !!p.sitemap,
      priority: p.priority ?? '',
      slug: p.slug || '',
      missing: !templates.value.includes(p.template || name),
    }
  })
)

async function run(fn, done) {
  busy.value = true
  try {
    await fn()
    ui.toast(done)
    await site.load()
    await load()
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}
const saveSeo = () => run(() => SiteService.saveSeo(seo.value), 'Opzioni degli indirizzi salvate.')
const setSitemap = (page, on) =>
  run(
    () => site.apply([{ op: 'set_sitemap', page: page.name, sitemap: on }]),
    on ? 'La pagina è nella sitemap.' : 'La pagina non è più nella sitemap.'
  )
// Togliere una pagina: i suoi indirizzi, già nei link e su Google, possono portare a un'altra (301)
const removing = ref(null)
function unregister(page) {
  editing.value = null
  adding.value = false
  removing.value = { name: page.name, to: '/' }
}
function confirmUnregister() {
  const r = removing.value
  const op = { op: 'unregister_page', page: r.name }
  if (r.to.trim()) op.redirect_to = r.to.trim()
  run(async () => {
    await site.apply([op])
    removing.value = null
  }, r.to.trim() ? `Pagina tolta: i suoi indirizzi portano a ${r.to.trim()}.` : 'Pagina tolta.')
}
const targets = computed(() =>
  pages.value.filter((p) => p.name !== removing.value?.name && (p.fixed || p.name === 'homepage')).map((p) => (p.name === 'homepage' ? '/' : `/${p.name}`))
)

// Nuova pagina: un template del tema che rende un contenuto fisso o uno per contenuto
const adding = ref(false)
const draft = ref({ template: '', type: '', mode: 'each', item: 0, sitemap: true })
const freeTemplates = computed(() =>
  templates.value.filter((t) => !site.schema.pages?.[t] && !['404', 'kris_head', 'kris_wip'].includes(t))
)
function addPage() {
  const d = draft.value
  const rule = d.mode === 'each' ? { key: d.type } : { key: d.type, id: Number(d.item) }
  const ops = [{ op: 'register_page', page: d.template, sitemap: rule }]
  if (!d.sitemap) ops.push({ op: 'set_sitemap', page: d.template, sitemap: false })
  run(async () => {
    await site.apply(ops)
    adding.value = false
  }, 'Pagina pubblica aggiunta.')
}

// Modifica di una pagina già pubblica (set_page): il nome, e quindi l'indirizzo e i link dei template, resta
const editing = ref(null)
const textFields = (type) => (site.fieldsOf(type) || []).filter((f) => f.type === 'text')
function edit(p) {
  adding.value = false
  removing.value = null
  editing.value = {
    name: p.name,
    template: p.template,
    type: p.type,
    mode: p.fixed ? 'fixed' : 'each',
    item: p.item,
    sitemap: p.sitemap,
    priority: p.priority === '' ? '' : String(p.priority),
    slug: p.slug,
  }
}
function savePage() {
  const e = editing.value
  const op = {
    op: 'set_page',
    page: e.name,
    template: e.template,
    type: e.type,
    item: e.mode === 'each' ? null : Number(e.item),
    sitemap: e.sitemap,
    priority: e.priority === '' ? null : Number(e.priority),
  }
  op.slug = e.mode === 'each' ? e.slug : ''
  run(async () => {
    await site.apply([op])
    editing.value = null
  }, `Pagina «${e.name}» salvata.`)
}

function saveRedirects() {
  const map = Object.fromEntries(redirects.value.filter((r) => r.from.trim()).map((r) => [r.from.trim(), r.to.trim()]))
  run(() => SiteService.saveRedirects(map), 'Reindirizzamenti salvati.')
}
</script>

<template>
  <div class="container">
    <div v-if="session.isStaging" class="notice notice-warning">
      <strong>Solo sul sito pubblico.</strong>
      <p>Gli indirizzi si cambiano sul sito pubblico: passa a «Sito pubblico» per modificarli.</p>
    </div>
    <p v-if="!loaded" class="loading">Carico gli indirizzi…</p>
    <div v-else class="settings-layout">
      <nav class="settings-nav" aria-label="Sezioni degli indirizzi">
        <router-link
          v-for="t in TABS"
          :key="t.key"
          :to="{ name: 'addresses', params: { tab: t.key } }"
          :class="{ active: t.key === tab }"
          :aria-current="t.key === tab ? 'page' : undefined"
        >
          <KIcon :name="t.icon" /><span
            ><strong>{{ t.label }}</strong
            ><small>{{ t.hint }}</small></span
          >
        </router-link>
      </nav>
      <div class="settings-content">
        <div v-if="tab === 'pagine'" class="box">
          <div class="bar">
            <h2 style="flex: 1">Pagine pubbliche</h2>
            <button type="button" class="btn btn-white btn-small" :disabled="readOnly || !freeTemplates.length" @click="adding = !adding">
              <KIcon name="plus" />Nuova pagina
            </button>
          </div>
          <form v-if="adding" class="collection-info-form" style="margin: 12px 0" @submit.prevent="addPage">
            <div class="form-grid">
              <label class="field-label"
                >Template<select v-model="draft.template" class="input" required>
                  <option value="" disabled>Scegli…</option>
                  <option v-for="t in freeTemplates" :key="t" :value="t">template/{{ t }}.html</option>
                </select></label
              >
              <label class="field-label"
                >Mostra<select v-model="draft.type" class="input" required>
                  <option value="" disabled>Scegli…</option>
                  <option v-for="t in site.userTypes" :key="t.name" :value="t.name">{{ site.label(t.name) }}</option>
                </select></label
              >
              <label class="field-label"
                >Quale contenuto<select v-model="draft.mode" class="input">
                  <option value="each">Una pagina per contenuto</option>
                  <option value="fixed">Un contenuto fisso</option>
                </select></label
              >
              <label v-if="draft.mode === 'fixed'" class="field-label"
                >Contenuto<select v-model.number="draft.item" class="input">
                  <option v-for="i in site.items(draft.type)" :key="i.id" :value="i.id">#{{ i.id }}</option>
                </select></label
              >
            </div>
            <label class="check-row"
              ><input v-model="draft.sitemap" type="checkbox" /><span
                ><strong>Nella sitemap</strong><small>Spento per pagine raggiungibili solo da chi conosce l'indirizzo.</small></span
              ></label
            >
            <button class="btn btn-primary btn-small" :disabled="busy">Aggiungi la pagina</button>
          </form>
          <div style="overflow-x: auto">
            <table class="table-list" style="margin-top: 8px">
              <thead>
                <tr>
                  <th scope="col">Pagina</th>
                  <th scope="col">Indirizzo</th>
                  <th scope="col">Mostra</th>
                  <th scope="col">Template</th>
                  <th scope="col">Sitemap</th>
                  <th scope="col"><span class="sr-only">Azioni</span></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="p in pages" :key="p.name">
                  <td>
                    <strong style="font-weight: 500">{{ p.name }}</strong>
                  </td>
                  <td class="mono">
                    {{ p.address }}<template v-if="p.slug"> · per {{ p.slug }}</template>
                  </td>
                  <td>{{ p.label }}{{ p.fixed ? '' : ', ogni contenuto' }}</td>
                  <td class="mono">{{ p.template }}.html <span v-if="p.missing" class="pill pill-danger">Manca</span></td>
                  <td>
                    <KSwitch
                      :model-value="p.sitemap"
                      :label="`Nella sitemap: ${p.name}`"
                      :disabled="readOnly || busy"
                      @update:model-value="setSitemap(p, $event)"
                    />
                  </td>
                  <td>
                    <div class="row-actions">
                      <button type="button" class="btn btn-white btn-small" :disabled="readOnly || busy" @click="edit(p)">Modifica</button>
                      <button
                        v-if="p.name !== 'homepage'"
                        type="button"
                        class="icon-button danger"
                        :aria-label="`Togli la pagina ${p.name}`"
                        :disabled="readOnly || busy"
                        @click="unregister(p)"
                      >
                        <KIcon name="close" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <form
            v-if="removing"
            class="box collection-info-form"
            style="background: var(--surface-2); margin-top: 12px"
            @submit.prevent="confirmUnregister"
          >
            <h2>Togliere la pagina «{{ removing.name }}»?</h2>
            <p class="hint">Non sarà più una pagina pubblica e sparisce dalla sitemap. Il template e i contenuti restano.</p>
            <label class="field-label"
              >I suoi indirizzi portano a<input
                v-model="removing.to"
                class="input mono"
                type="text"
                list="kris-redirect-targets"
                placeholder="Vuoto: rispondono «pagina non trovata»"
            /></label>
            <datalist id="kris-redirect-targets">
              <option v-for="t in targets" :key="t" :value="t" />
            </datalist>
            <p class="hint">Chi arriva da un link o da Google va lì (301), in ogni lingua. Lo trovi poi in Reindirizzamenti.</p>
            <div class="bar">
              <button type="button" class="btn btn-white btn-small" @click="removing = null">Annulla</button
              ><button class="btn btn-danger btn-small" :disabled="busy">Togli la pagina</button>
            </div>
          </form>
          <form
            v-if="editing"
            class="box collection-info-form"
            style="background: var(--surface-2); margin-top: 12px"
            @submit.prevent="savePage"
          >
            <h2>Pagina «{{ editing.name }}»</h2>
            <div class="form-grid">
              <label class="field-label"
                >Template<select v-model="editing.template" class="input" required>
                  <option v-for="t in templates" :key="t" :value="t">{{ t }}.html</option>
                </select></label
              >
              <label class="field-label"
                >Mostra<select v-model="editing.type" class="input" required>
                  <option v-for="t in site.userTypes" :key="t.name" :value="t.name">{{ site.label(t.name) }}</option>
                  <option v-if="!site.userTypes.some((t) => t.name === editing.type)" :value="editing.type">
                    {{ site.type(editing.type) ? site.label(editing.type) : editing.type }}
                  </option>
                </select></label
              >
              <label class="field-label"
                >Quale contenuto<select v-model="editing.mode" class="input" :disabled="editing.name === 'homepage'">
                  <option value="each">Una pagina per contenuto</option>
                  <option value="fixed">Un contenuto fisso</option>
                </select></label
              >
              <label v-if="editing.mode === 'fixed'" class="field-label"
                >Contenuto<select v-model.number="editing.item" class="input">
                  <option v-for="i in site.items(editing.type)" :key="i.id" :value="i.id">#{{ i.id }}</option>
                </select></label
              >
              <label v-else class="field-label"
                >Indirizzo dei contenuti<select v-model="editing.slug" class="input">
                  <option value="">Il numero del contenuto (/{{ editing.name }}/3)</option>
                  <option v-for="f in textFields(editing.type)" :key="f.name" :value="f.name">
                    Dal campo «{{ f.description || f.name }}»
                  </option>
                </select></label
              >
              <label class="field-label"
                >Priorità nella sitemap<input
                  v-model="editing.priority"
                  class="input"
                  type="number"
                  min="0"
                  max="1"
                  step="0.1"
                  placeholder="Predefinita"
              /></label>
            </div>
            <label class="check-row"
              ><input v-model="editing.sitemap" type="checkbox" /><span
                ><strong>Nella sitemap</strong><small>Spento per pagine raggiungibili solo da chi conosce l'indirizzo.</small></span
              ></label
            >
            <p class="hint">
              Il nome della pagina resta: è il suo indirizzo e lo usano i link dei template. Per un indirizzo diverso crea una pagina nuova
              e aggiungi un reindirizzamento dal vecchio.
            </p>
            <div class="bar">
              <button type="button" class="btn btn-white btn-small" @click="editing = null">Annulla</button
              ><button class="btn btn-primary btn-small" :disabled="busy">Salva la pagina</button>
            </div>
          </form>
        </div>

        <div v-if="tab === 'reindirizzamenti'" class="box">
          <div class="bar">
            <h2 style="flex: 1">Reindirizzamenti</h2>
            <button type="button" class="btn btn-white btn-small" :disabled="readOnly" @click="redirects.push({ from: '', to: '' })">
              <KIcon name="plus" />Aggiungi
            </button>
          </div>
          <p class="muted" style="margin: 4px 0 10px">
            Un vecchio indirizzo porta alla pagina nuova (301): chi arriva da un link o da Google non trova una pagina vuota. Con /* in
            fondo (/progetti-vecchi/*) vale per tutto quello che c'era sotto. Cambiando lo slug di un contenuto, Kris aggiunge da solo
            il reindirizzamento dal vecchio indirizzo.
          </p>
          <form @submit.prevent="saveRedirects">
            <div v-for="(r, i) in redirects" :key="i" class="inline-form" style="margin-bottom: 8px">
              <input
                v-model="r.from"
                class="input mono"
                type="text"
                placeholder="/vecchio-indirizzo"
                :aria-label="`Da (${i + 1})`"
                :disabled="readOnly"
              />
              <span aria-hidden="true">→</span>
              <input
                v-model="r.to"
                class="input mono"
                type="text"
                placeholder="/nuovo o https://…"
                :aria-label="`A (${i + 1})`"
                :disabled="readOnly"
              />
              <button
                type="button"
                class="icon-button danger"
                :aria-label="`Togli il reindirizzamento ${i + 1}`"
                :disabled="readOnly"
                @click="redirects.splice(i, 1)"
              >
                <KIcon name="close" />
              </button>
            </div>
            <p v-if="!redirects.length" class="muted">Nessun reindirizzamento.</p>
            <button class="btn btn-white btn-small" style="margin-top: 8px" :disabled="readOnly || busy">Salva i reindirizzamenti</button>
          </form>
        </div>

        <form v-if="tab === 'opzioni'" class="box collection-info-form" @submit.prevent="saveSeo">
          <h2>Opzioni</h2>
          <label class="check-row"
            ><input v-model="seo.auto_language" type="checkbox" :disabled="readOnly" /><span
              ><strong>Lingua automatica</strong
              ><small>Se l'indirizzo non indica una lingua, la homepage propone quella del visitatore.</small></span
            ></label
          >
          <label class="check-row"
            ><input v-model="seo.head" type="checkbox" :disabled="readOnly" /><span
              ><strong>Canonical e lingue alternative automatici</strong
              ><small>Kris li aggiunge alle pagine che non li hanno già nel template.</small></span
            ></label
          >
          <label class="field-label"
            >Indirizzo del sito<input
              v-model="seo.base_url"
              class="input mono"
              type="url"
              placeholder="https://www.esempio.it"
              :disabled="readOnly"
          /></label>
          <p class="hint">Per sitemap e canonical. Vuoto: lo ricava dalla richiesta.</p>
          <label class="field-label"
            >Righe in più di robots.txt<textarea
              v-model="seo.robots"
              class="input mono"
              rows="3"
              placeholder="Disallow: /privato/"
              :disabled="readOnly"
            ></textarea>
          </label>
          <button class="btn btn-white btn-small" :disabled="readOnly || busy">Salva le opzioni</button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import KSwitch from '@/components/KSwitch.vue'
import api from '@/plugins/api'
import SiteService from '@/services/SiteService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

/** Le pagine pubbliche, gli indirizzi leggibili e i reindirizzamenti dai vecchi indirizzi. */
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const loaded = ref(false)
const templates = ref([])
const seo = ref({ pretty_urls: false, auto_language: false, head: true, base_url: '', robots: '' })
const redirects = ref([])
const busy = ref(false)
const readOnly = computed(() => session.isStaging || session.locked)

async function load() {
  try {
    const r = await api.get('pages')
    templates.value = r.templates
    seo.value = { pretty_urls: !!r.seo.pretty_urls, auto_language: !!r.seo.auto_language, head: r.seo.head !== false, base_url: r.seo.base_url || '', robots: (r.seo.robots || []).join('\n') }
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
    return { name, address, type: p.type, label: site.type(p.type) ? site.label(p.type) : p.type, fixed, template: p.template || name, sitemap: !!p.sitemap, slug: p.slug || '', missing: !templates.value.includes(p.template || name) }
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
const setSitemap = (page, on) => run(() => site.apply([{ op: 'set_sitemap', page: page.name, sitemap: on }]), on ? 'La pagina è nella sitemap.' : 'La pagina non è più nella sitemap.')
async function unregister(page) {
  if (!(await ui.confirm({ title: `Togliere la pagina «${page.name}»?`, text: 'Non sarà più una pagina pubblica e sparisce dalla sitemap. Il template e i contenuti restano.', confirmLabel: 'Togli pagina' }))) return
  run(() => site.apply([{ op: 'unregister_page', page: page.name }]), 'Pagina tolta.')
}

// Nuova pagina: un template del tema che rende un contenuto fisso o uno per contenuto
const adding = ref(false)
const draft = ref({ template: '', type: '', mode: 'each', item: 0, sitemap: true })
const freeTemplates = computed(() => templates.value.filter((t) => !site.schema.pages?.[t] && !['404', 'kris_head', 'kris_wip'].includes(t)))
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

function saveRedirects() {
  const map = Object.fromEntries(redirects.value.filter((r) => r.from.trim()).map((r) => [r.from.trim(), r.to.trim()]))
  run(() => SiteService.saveRedirects(map), 'Reindirizzamenti salvati.')
}
</script>

<template>
  <div class="container">
    <div class="page-head"><div><h1>Indirizzi</h1><p class="sub">Le pagine pubbliche del sito e dove portano i vecchi indirizzi.</p></div></div>
    <div v-if="session.isStaging" class="notice notice-warning"><strong>Solo sul sito pubblico.</strong><p>Gli indirizzi si cambiano sul sito pubblico: passa a «Sito pubblico» per modificarli.</p></div>
    <p v-if="!loaded" class="loading">Carico gli indirizzi…</p>
    <template v-else>
      <div class="box">
        <div class="bar"><h2 style="flex: 1">Pagine pubbliche</h2><button type="button" class="btn btn-white btn-small" :disabled="readOnly || !freeTemplates.length" @click="adding = !adding"><KIcon name="plus" />Nuova pagina</button></div>
        <form v-if="adding" class="collection-info-form" style="margin: 12px 0" @submit.prevent="addPage">
          <div class="form-grid">
            <label class="field-label">Template<select v-model="draft.template" class="input" required><option value="" disabled>Scegli…</option><option v-for="t in freeTemplates" :key="t" :value="t">template/{{ t }}.html</option></select></label>
            <label class="field-label">Mostra<select v-model="draft.type" class="input" required><option value="" disabled>Scegli…</option><option v-for="t in site.userTypes" :key="t.name" :value="t.name">{{ site.label(t.name) }}</option></select></label>
            <label class="field-label">Quale contenuto<select v-model="draft.mode" class="input"><option value="each">Una pagina per contenuto</option><option value="fixed">Un contenuto fisso</option></select></label>
            <label v-if="draft.mode === 'fixed'" class="field-label">Contenuto<select v-model.number="draft.item" class="input"><option v-for="i in site.items(draft.type)" :key="i.id" :value="i.id">#{{ i.id }}</option></select></label>
          </div>
          <label class="check-row"><input v-model="draft.sitemap" type="checkbox" /><span><strong>Nella sitemap</strong><small>Spento per pagine raggiungibili solo da chi conosce l'indirizzo.</small></span></label>
          <button class="btn btn-primary btn-small" :disabled="busy">Aggiungi la pagina</button>
        </form>
        <div style="overflow-x: auto">
          <table class="table-list" style="margin-top: 8px">
            <thead><tr><th scope="col">Pagina</th><th scope="col">Indirizzo</th><th scope="col">Mostra</th><th scope="col">Template</th><th scope="col">Sitemap</th><th scope="col"><span class="sr-only">Azioni</span></th></tr></thead>
            <tbody>
              <tr v-for="p in pages" :key="p.name">
                <td><strong style="font-weight: 500">{{ p.name }}</strong></td>
                <td class="mono">{{ seo.pretty_urls ? p.address : `?page=${p.name}` }}<template v-if="p.slug"> · per {{ p.slug }}</template></td>
                <td>{{ p.label }}{{ p.fixed ? '' : ', ogni contenuto' }}</td>
                <td class="mono">{{ p.template }}.html <span v-if="p.missing" class="pill pill-danger">Manca</span></td>
                <td><KSwitch :model-value="p.sitemap" :label="`Nella sitemap: ${p.name}`" :disabled="readOnly || busy" @update:model-value="setSitemap(p, $event)" /></td>
                <td><button v-if="p.name !== 'homepage'" type="button" class="icon-button danger" :aria-label="`Togli la pagina ${p.name}`" :disabled="readOnly || busy" @click="unregister(p)"><KIcon name="close" /></button></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="box">
        <div class="bar"><h2 style="flex: 1">Reindirizzamenti</h2><button type="button" class="btn btn-white btn-small" :disabled="readOnly" @click="redirects.push({ from: '', to: '' })"><KIcon name="plus" />Aggiungi</button></div>
        <p class="muted" style="margin: 4px 0 10px">Un vecchio indirizzo porta alla pagina nuova (301): chi arriva da un link o da Google non trova una pagina vuota.</p>
        <form @submit.prevent="saveRedirects">
          <div v-for="(r, i) in redirects" :key="i" class="inline-form" style="margin-bottom: 8px">
            <input v-model="r.from" class="input mono" type="text" placeholder="/vecchio-indirizzo" :aria-label="`Da (${i + 1})`" :disabled="readOnly" />
            <span aria-hidden="true">→</span>
            <input v-model="r.to" class="input mono" type="text" placeholder="/nuovo o https://…" :aria-label="`A (${i + 1})`" :disabled="readOnly" />
            <button type="button" class="icon-button danger" :aria-label="`Togli il reindirizzamento ${i + 1}`" :disabled="readOnly" @click="redirects.splice(i, 1)"><KIcon name="close" /></button>
          </div>
          <p v-if="!redirects.length" class="muted">Nessun reindirizzamento.</p>
          <button class="btn btn-white btn-small" style="margin-top: 8px" :disabled="readOnly || busy">Salva i reindirizzamenti</button>
        </form>
      </div>

      <form class="box collection-info-form" @submit.prevent="saveSeo">
        <h2>Opzioni</h2>
        <label class="check-row"><input v-model="seo.pretty_urls" type="checkbox" :disabled="readOnly" /><span><strong>Indirizzi leggibili</strong><small>/menu e /progetto/casa-rossi invece di index.php?page=…; i vecchi indirizzi portano ai nuovi. Serve la regola del .htaccess di Kris.</small></span></label>
        <label class="check-row"><input v-model="seo.auto_language" type="checkbox" :disabled="readOnly" /><span><strong>Lingua automatica</strong><small>Se l'indirizzo non indica una lingua, la homepage propone quella del visitatore.</small></span></label>
        <label class="check-row"><input v-model="seo.head" type="checkbox" :disabled="readOnly" /><span><strong>Canonical e lingue alternative automatici</strong><small>Kris li aggiunge alle pagine che non li hanno già nel template.</small></span></label>
        <label class="field-label">Indirizzo del sito<input v-model="seo.base_url" class="input mono" type="url" placeholder="https://www.esempio.it" :disabled="readOnly" /></label>
        <p class="hint">Per sitemap e canonical. Vuoto: lo ricava dalla richiesta.</p>
        <label class="field-label">Righe in più di robots.txt<textarea v-model="seo.robots" class="input mono" rows="3" placeholder="Disallow: /privato/" :disabled="readOnly"></textarea></label>
        <button class="btn btn-white btn-small" :disabled="readOnly || busy">Salva le opzioni</button>
      </form>
    </template>
  </div>
</template>

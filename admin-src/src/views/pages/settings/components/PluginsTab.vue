<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import KSwitch from '@/components/KSwitch.vue'
import PluginService from '@/services/PluginService'
import { useSessionStore } from '@/stores/session'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/views/pages/media/format'

/**
 * Plugin (docs/2.0/plugin.md, 8): installati, catalogo ufficiale, installazione con la
 * password. Un plugin installato è spento finché non lo accendi: prima puoi compilarne
 * le impostazioni. Accenderlo mostra i domini esterni e i cookie che dichiara.
 */
const session = useSessionStore()
const ui = useUiStore()

const data = ref(null)
const catalog = ref(null)
const loading = ref(true)
const busy = ref(false)
const choosing = ref(null) // { name, title, releases, version, password }
const removing = ref(null) // { name, title, password, deleteData }

async function load() {
  try {
    data.value = await PluginService.list()
  } catch (e) {
    ui.toast(e.message)
  }
  loading.value = false
}
onMounted(load)

const installed = computed(() => data.value?.installed || [])
const blocked = computed(() => session.isStaging || data.value?.staging)
const notInstalled = computed(() => (catalog.value || []).filter((p) => !installed.value.some((i) => i.name === p.name)))
const categories = { technical: 'Tecnico', thirdparty: 'Terze parti', statistics: 'Statistiche', marketing: 'Marketing' }

async function refreshAll() {
  await Promise.all([load(), session.load()])
}

async function openCatalog() {
  busy.value = true
  try {
    catalog.value = (await PluginService.catalog()).plugins
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}

async function choose(p) {
  busy.value = true
  try {
    const r = await PluginService.releases(p.name)
    if (!r.latest) throw new Error(`Nessuna versione di ${p.title} va su questo sito: serve una versione di Kris o di PHP più recente.`)
    choosing.value = { name: p.name, title: p.title, releases: r.releases, version: r.latest, password: '', installed: installed.value.find((i) => i.name === p.name)?.version || null }
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}

async function install() {
  const c = choosing.value
  busy.value = true
  try {
    await PluginService.install(c.name, c.version, c.password)
    choosing.value = null
    await refreshAll()
    ui.toast(c.installed ? `${c.title} aggiornato alla ${c.version}.` : `${c.title} installato. È spento: compila le impostazioni, poi accendilo.`)
  } catch (e) {
    ui.toast(e.message)
    c.password = ''
  }
  busy.value = false
}

async function setActive(p, on) {
  if (on) {
    const lines = []
    if (p.domains.length) lines.push(`Domini esterni: ${p.domains.join(', ')}.`)
    if (p.cookies.length) lines.push(`Cookie: ${p.cookies.map((c) => `${c.name} (${categories[c.category]})`).join(', ')}.`)
    const ok = await ui.confirm({
      title: `Accendere ${p.title}?`,
      text: `Il plugin esegue codice sul server con gli stessi permessi di Kris. ${lines.join(' ') || 'Non dichiara domini esterni né cookie.'} Aggiorna l'informativa se serve.`,
      confirmLabel: 'Accendi',
      danger: false,
    })
    if (!ok) return
  }
  busy.value = true
  try {
    await (on ? PluginService.activate(p.name) : PluginService.deactivate(p.name))
    await refreshAll()
    ui.toast(on ? `${p.title} è acceso.` : `${p.title} è spento: i suoi dati restano.`)
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}

async function remove() {
  const r = removing.value
  busy.value = true
  try {
    await PluginService.remove(r.name, r.password, r.deleteData)
    removing.value = null
    await refreshAll()
    ui.toast(r.deleteData ? `${r.title} è stato tolto con i suoi dati.` : `${r.title} è stato tolto. I suoi dati restano nell'archivio.`)
  } catch (e) {
    ui.toast(e.message)
    r.password = ''
  }
  busy.value = false
}
</script>

<template>
  <section class="card">
    <header class="card-header">
      <span class="collection-icon"><KIcon name="puzzle" /></span>
      <div><h2>Plugin</h2><p>Funzioni in più dal catalogo ufficiale di Kris, firmate come gli aggiornamenti: misurazione, avvisi ai motori di ricerca.</p></div>
    </header>
    <div class="card-body">
      <p v-if="loading" class="muted">Carico i plugin…</p>
      <template v-else-if="data">
        <div v-if="data.safe_mode" class="alert alert-warning" role="status"><KIcon name="warning" /><p><strong>Modalità sicura.</strong> config/plugins.php spegne tutti i plugin: nessuno gira finché non la togli.</p></div>
        <div v-if="blocked" class="alert" role="status">
          <p>{{ session.isStaging ? 'I plugin si installano e si accendono sul sito pubblico: passa a «Sito pubblico».' : 'Ci sono modifiche in attesa: pubblicale o scartale prima di installare o togliere un plugin.' }}</p>
        </div>

        <p v-if="!installed.length" class="muted">Nessun plugin installato.</p>
        <div v-else style="overflow-x: auto">
          <table class="table-list">
            <thead><tr><th scope="col">Plugin</th><th scope="col">Stato</th><th scope="col"><span class="sr-only">Azioni</span></th></tr></thead>
            <tbody>
              <tr v-for="p in installed" :key="p.name">
                <td>
                  <strong style="font-weight: 500">{{ p.title }}</strong> <span class="mono muted">{{ p.version }}</span>
                  <div class="muted">{{ p.description }}</div>
                  <div v-if="p.disabled_reason" class="field-error">Spento per un errore: {{ p.disabled_reason }}</div>
                  <div v-else-if="p.last_error" class="muted">Ultimo errore ({{ formatDate(p.last_error.at) }}): {{ p.last_error.message }}</div>
                  <div v-if="p.database_recommended && data.store !== 'pdo'" class="muted">Scrive spesso: conviene l'archivio nel database (Impostazioni › Archivio dei dati).</div>
                  <details v-if="p.log.length" class="update-manual"><summary>Registro</summary><pre class="mono" style="white-space: pre-wrap; font-size: 12px">{{ p.log.join('\n') }}</pre></details>
                </td>
                <td><KSwitch :model-value="p.active" :label="`${p.title} acceso`" :disabled="busy || session.isStaging || data.safe_mode" @update:model-value="setActive(p, $event)" /></td>
                <td>
                  <div class="row-actions">
                    <router-link v-if="p.settings" class="btn btn-white btn-small" :to="{ name: 'plugin-settings', params: { name: p.name } }">Impostazioni</router-link>
                    <button type="button" class="btn btn-white btn-small" :disabled="busy || blocked" @click="choose(p)">Versioni</button>
                    <button type="button" class="icon-button danger" :aria-label="`Togli ${p.title}`" :disabled="busy || blocked" @click="removing = { name: p.name, title: p.title, password: '', deleteData: false }"><KIcon name="trash" /></button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <form v-if="choosing" class="box collection-info-form" style="background: var(--surface-2)" @submit.prevent="install">
          <h2>{{ choosing.installed ? `Versioni di ${choosing.title}` : `Installa ${choosing.title}` }}</h2>
          <div class="form-grid">
            <label class="field-label">Versione
              <select v-model="choosing.version" class="input">
                <option v-for="r in choosing.releases" :key="r.version" :value="r.version">{{ r.version }}{{ r.version === choosing.installed ? ' (installata)' : '' }}{{ r.date ? ` · ${r.date}` : '' }}</option>
              </select>
            </label>
            <label class="field-label">La tua password<input v-model="choosing.password" class="input" type="password" autocomplete="current-password" required /></label>
          </div>
          <p v-if="choosing.releases.find((r) => r.version === choosing.version)?.changelog" class="hint">{{ choosing.releases.find((r) => r.version === choosing.version).changelog }}</p>
          <p class="hint">Il plugin esegue codice sul server con gli stessi permessi di Kris. Prima di installare si salva una copia dei contenuti: se qualcosa va storto, il sito torna com'era.</p>
          <div class="bar"><button type="button" class="btn btn-white btn-small" @click="choosing = null">Annulla</button><button class="btn btn-primary btn-small" :disabled="busy || blocked">{{ busy ? 'Installo…' : 'Installa' }}</button></div>
        </form>

        <form v-if="removing" class="box collection-info-form" style="background: var(--surface-2)" @submit.prevent="remove">
          <h2>Togliere {{ removing.title }}?</h2>
          <label class="check-row"><input v-model="removing.deleteData" type="checkbox" /> Elimina anche i suoi dati (impostazioni e contenuti del plugin). Non si può annullare.</label>
          <label class="field-label">La tua password<input v-model="removing.password" class="input" type="password" autocomplete="current-password" required /></label>
          <div class="bar"><button type="button" class="btn btn-white btn-small" @click="removing = null">Annulla</button><button class="btn btn-danger btn-small" :disabled="busy">Togli il plugin</button></div>
        </form>

        <div v-if="catalog === null"><button type="button" class="btn btn-primary btn-small" :disabled="busy || blocked" @click="openCatalog"><KIcon name="plus" />Aggiungi un plugin</button></div>
        <template v-else>
          <div class="section-head"><h2>Catalogo</h2><span>Plugin ufficiali, firmati con le chiavi di Kris</span></div>
          <p v-if="!notInstalled.length" class="muted">Hai già tutti i plugin del catalogo.</p>
          <div v-else class="grid">
            <div v-for="p in notInstalled" :key="p.name" class="tile">
              <div class="tile-head"><span class="ic"><KIcon name="puzzle" /></span><span class="tile-name">{{ p.title }}</span></div>
              <p class="tile-text">{{ p.description }}</p>
              <div class="tile-foot"><button type="button" class="btn btn-white btn-small" :disabled="busy || blocked" @click="choose(p)">Installa</button></div>
            </div>
          </div>
        </template>

        <details v-if="data.cookies.length || data.domains.length" class="update-manual">
          <summary>Cookie e domini dei plugin accesi</summary>
          <p class="hint">Per l'informativa: li dichiarano i plugin, Kris non li verifica sul sito.</p>
          <table v-if="data.cookies.length" class="table-list">
            <thead><tr><th scope="col">Cookie</th><th scope="col">Categoria</th><th scope="col">Durata</th><th scope="col">Fornitore e finalità</th></tr></thead>
            <tbody><tr v-for="c in data.cookies" :key="c.plugin + c.name"><td class="mono">{{ c.name }}</td><td>{{ categories[c.category] }}</td><td>{{ c.duration }}</td><td>{{ c.provider }}<span v-if="c.purpose"> · {{ c.purpose }}</span><div class="muted">{{ c.plugin }}</div></td></tr></tbody>
          </table>
          <p v-if="data.domains.length" class="hint">Domini esterni: <span class="mono">{{ data.domains.join(', ') }}</span></p>
        </details>
      </template>
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import VersionService from '@/services/VersionService'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/views/pages/media/format'

/**
 * Versione di Kris e aggiornamenti (update.php): verifica, scaricamento o caricamento
 * dello zip, installazione, ritorno alla versione precedente, ripristino dopo un'interruzione.
 * Le note di rilascio arrivano dal canale ed entrano nella pagina solo come testo.
 */
const ui = useUiStore()
const data = ref(null)
const busy = ref(false)
const result = ref(null) // { kind, title, text, notes, target, major, warnings, blocked }
const steps = ref([])
const file = ref(null)

const OUTCOMES = { ok: 'riuscito', rollback: 'annullato, sito ripristinato', error: 'non riuscito', manual_rollback: 'tornati alla versione precedente', ftp: 'file caricati a mano' }

async function load() {
  try {
    data.value = await VersionService.get()
  } catch (e) {
    ui.toast(e.message)
  }
}
onMounted(load)
// Durante un'installazione la pagina non va chiusa
const guard = (event) => {
  if (busy.value) {
    event.preventDefault()
    event.returnValue = ''
  }
}
window.addEventListener('beforeunload', guard)
onBeforeUnmount(() => window.removeEventListener('beforeunload', guard))
const reloadSoon = (delay) => setTimeout(() => window.location.reload(), delay)

async function call(op, fields) {
  try {
    return await VersionService.op(op, fields)
  } catch (e) {
    return { ok: false, error: e.message, unknown: true }
  }
}

async function check() {
  busy.value = true
  result.value = { kind: 'info', title: 'Controllo in corso…' }
  const r = await call('check')
  busy.value = false
  if (!r.ok) return (result.value = { kind: 'error', title: 'Verifica non riuscita', text: r.error, manual: true })
  result.value = r.target
    ? { kind: 'available', title: `È disponibile Kris ${r.target}`, text: `Installata ora: Kris ${r.current}.`, notes: r.notes || [], target: r.target, major: r.major, warnings: r.warnings || [], blocked: r.blocked }
    : r.unmet
      ? { kind: 'error', title: 'Aggiornamento non installabile su questo hosting', text: r.unmet, blocked: r.blocked }
      : { kind: 'info', title: 'Kris è aggiornato', text: `La versione installata (${r.current}) è la più recente installabile da qui.`, blocked: r.blocked }
}

async function install(prepareOp, fields) {
  steps.value = [{ label: prepareOp === 'upload' ? 'Carico e verifico il pacchetto' : 'Scarico e verifico il pacchetto', state: 'running' }]
  result.value = null
  busy.value = true
  const prepared = await call(prepareOp, fields)
  steps.value[0].state = prepared.ok ? 'done' : 'failed'
  busy.value = false
  if (!prepared.ok) return (result.value = { kind: 'error', title: 'Il sito non è stato modificato', text: prepared.error })
  const version = prepared.prepared.version
  const ok = await ui.confirm({
    title: `Installare Kris ${version}?`,
    text: 'Il sito resterà in manutenzione per qualche istante. Prima viene fatta una copia di sicurezza: se qualcosa non va, tutto torna com’era da solo. Non chiudere questa pagina finché non hai l’esito.',
    confirmLabel: 'Installa ora',
    danger: false,
  })
  if (!ok) return (result.value = { kind: 'info', title: 'Aggiornamento annullato', text: 'Il sito non è stato modificato.' })
  busy.value = true
  steps.value.push({ label: 'Installo e controllo il sito', state: 'running' })
  const r = await call('install')
  steps.value[steps.value.length - 1].state = r.ok ? 'done' : 'failed'
  for (const s of r.steps || []) steps.value.push({ label: s.label, state: s.ok ? 'done' : 'failed' })
  busy.value = false
  result.value = r.ok
    ? { kind: 'info', title: `Kris ${version} è installata`, text: 'Ricarico la pagina…' }
    : { kind: 'error', title: r.unknown ? 'Esito non disponibile' : r.rolled_back ? 'Aggiornamento annullato: il sito è tornato com’era' : 'Aggiornamento non riuscito: il sito non è stato modificato', text: r.error }
  reloadSoon(r.ok ? 2000 : 6000)
}

function upload() {
  if (!file.value?.files?.length) return ui.toast('Scegli prima il file zip del pacchetto.')
  install('upload', { package: file.value.files[0] })
}

async function rollback() {
  const ok = await ui.confirm({ title: `Tornare a Kris ${data.value.rollback.from}?`, text: 'Il sito torna com’era prima dell’ultimo aggiornamento, contenuti compresi: le modifiche fatte da allora andranno perse.', confirmLabel: 'Torna alla versione precedente' })
  if (!ok) return
  busy.value = true
  const r = await call('rollback')
  busy.value = false
  result.value = r.ok ? { kind: 'info', title: 'Versione precedente ripristinata', text: 'Ricarico la pagina…' } : { kind: 'error', title: 'Non è stato possibile tornare indietro', text: r.error }
  reloadSoon(r.ok ? 1500 : 6000)
}

async function recover() {
  busy.value = true
  const r = await call('recover')
  busy.value = false
  ui.toast(r.ok ? 'Sito ripristinato.' : r.error || 'Ripristino non riuscito.')
  reloadSoon(1200)
}
</script>

<template>
  <section class="card">
    <header class="card-header"><span class="collection-icon"><KIcon name="version" /></span><div><h2>Versione di Kris</h2></div></header>
    <div v-if="data" class="card-body">
      <div v-if="data.state_error" class="alert alert-error" role="alert"><p>Lo storico degli aggiornamenti non è leggibile (file <code>data/kris_state.json</code>). I contenuti non sono coinvolti: segnalalo a chi gestisce il sito.</p></div>
      <div class="setting-row static"><span><strong>Versione installata</strong><small>Indicala quando chiedi assistenza.</small></span><span class="setting-value">Kris {{ data.version }}</span></div>
      <div class="setting-row static"><span><strong>Formato dei contenuti</strong><small>Cambia solo quando un aggiornamento converte i dati.</small></span><span class="setting-value">{{ data.data_version ?? 'Non ancora registrato' }}</span></div>
      <div class="setting-row static">
        <span><strong>Ultimo controllo degli aggiornamenti</strong><small v-if="data.last_check?.error">Non riuscito: {{ data.last_check.error }}</small><small v-else-if="data.last_check?.latest">Era disponibile Kris {{ data.last_check.latest }}</small></span>
        <span class="setting-value">{{ data.last_check?.at ? formatDate(data.last_check.at) : 'Mai' }}</span>
      </div>
      <div class="setting-row static">
        <span><strong>Ultimo aggiornamento</strong><small v-if="data.last_update">Da {{ data.last_update.from || '?' }} a {{ data.last_update.to || '?' }}, {{ OUTCOMES[data.last_update.outcome] || 'esito sconosciuto' }}</small></span>
        <span class="setting-value">{{ data.last_update ? formatDate(data.last_update.at) : 'Nessuno' }}</span>
      </div>

      <div v-if="data.maintenance?.stale" class="update-panel">
        <div class="alert alert-error" role="alert"><p><strong>Un aggiornamento si è interrotto.</strong> Il sito potrebbe essere rimasto a metà. Ripristinando, torna esattamente com'era prima dell'aggiornamento.</p></div>
        <button type="button" class="btn btn-primary" :disabled="busy" @click="recover">Ripristina il sito</button>
      </div>
      <div v-else-if="data.maintenance" class="notice"><strong>Aggiornamento in corso.</strong><p>Ricarica la pagina tra qualche minuto.</p></div>
      <div v-else-if="!data.enabled" class="notice"><strong>Aggiornamenti gestiti dallo sviluppatore.</strong><p>Su questo sito gli aggiornamenti di Kris non si installano dall'editor.</p></div>
      <div v-else class="update-panel">
        <div class="update-actions">
          <button type="button" class="btn btn-white" :disabled="busy" @click="check">Verifica aggiornamenti</button>
          <span class="hint">Prima di ogni aggiornamento viene fatta una copia di sicurezza: se qualcosa non va, il sito torna com'era da solo.</span>
        </div>
        <ol v-if="steps.length" class="staging-problems" aria-live="polite">
          <li v-for="(s, i) in steps" :key="i">{{ s.label }} — {{ { running: 'in corso…', done: 'fatto', failed: 'non riuscito' }[s.state] }}</li>
        </ol>
        <div v-if="result" class="update-result" :role="result.kind === 'error' ? 'alert' : 'status'">
          <strong>{{ result.title }}</strong>
          <p v-if="result.text">{{ result.text }}</p>
          <ul v-if="result.notes?.length" class="staging-problems">
            <li v-for="n in result.notes" :key="n.version"><strong>Kris {{ n.version }}{{ n.date ? ` · ${n.date}` : '' }}</strong><br />{{ n.changelog }}</li>
          </ul>
          <p v-if="result.major" class="hint">È una versione principale nuova: serve PHP 8.3. Contenuti e template restano com'erano.</p>
          <div v-for="w in result.warnings || []" :key="w" class="notice notice-warning"><strong>Da controllare</strong><p>{{ w }}</p></div>
          <button v-if="result.target" type="button" class="btn btn-primary" style="align-self: flex-start" :disabled="busy" @click="install('prepare')">Aggiorna a Kris {{ result.target }}</button>
          <p v-if="result.blocked" class="hint">È uscita Kris {{ result.blocked }}: cambia versione principale e la installa lo sviluppatore, non l'editor.</p>
        </div>
        <details class="update-manual" :open="result?.manual">
          <summary>Carica un pacchetto a mano</summary>
          <p class="hint">Se l'hosting non riesce a scaricare gli aggiornamenti, scarica lo zip dalla pagina delle release di Kris e caricalo qui. La firma viene verificata prima di installare.</p>
          <div class="update-actions"><input ref="file" type="file" accept=".zip,application/zip" aria-label="Pacchetto zip di Kris" /><button type="button" class="btn btn-white" :disabled="busy" @click="upload">Carica e verifica</button></div>
        </details>
        <div v-if="data.rollback" class="update-rollback">
          <div><strong>Torna a Kris {{ data.rollback.from }}</strong><small>Possibile fino al {{ formatDate(data.rollback.expires_at) }}. Le modifiche ai contenuti fatte dopo l'aggiornamento del {{ formatDate(data.rollback.created_at) }} andranno perse.</small></div>
          <button type="button" class="btn btn-white" :disabled="busy" @click="rollback">Torna alla versione precedente</button>
        </div>
      </div>

      <div v-if="data.history.length" class="update-history">
        <p class="nav-label">Registro degli aggiornamenti</p>
        <ul>
          <li v-for="(h, i) in data.history" :key="i">
            <span>{{ formatDate(h.at) }}</span><span>Da {{ h.from || '?' }} a {{ h.to || '?' }} · {{ OUTCOMES[h.outcome] || 'esito sconosciuto' }}{{ h.user ? ` · ${h.user}` : '' }}</span>
            <small v-if="h.outcome === 'rollback' && h.message">{{ h.message }}</small>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import MediaService from '@/services/MediaService'
import { useSessionStore } from '@/stores/session'
import { useUiStore } from '@/stores/ui'
import { formatSize } from '@/views/pages/media/format'

const session = useSessionStore()
const ui = useUiStore()

const files = ref([])
const loading = ref(true)
const search = ref('')
const results = ref([]) // caricamenti di questa visita: { name, status, ok }
const over = ref(false)
const input = ref(null)
const optimizing = ref(false)

const limits = computed(() => session.data?.uploads || {})
const accept = computed(() => (limits.value.accept || []).map((e) => '.' + e).join(','))
const shown = computed(() => files.value.filter((f) => !search.value || f.name.toLowerCase().includes(search.value.toLowerCase())))

async function load() {
  try {
    files.value = await MediaService.list()
  } catch (e) {
    ui.toast(e.message)
  }
  loading.value = false
}
onMounted(load)

async function uploadAll(list) {
  for (const file of list) {
    const row = { name: file.name, status: 'Carico…', ok: null }
    results.value.unshift(row)
    try {
      const result = await MediaService.upload(file)
      row.ok = true
      row.status = result.staged ? 'Caricato fra le modifiche in attesa: sarà pubblico quando pubblichi.' : 'Caricato.'
    } catch (e) {
      row.ok = false
      row.status = e.message
    }
    results.value = [...results.value]
  }
  await load()
  await session.load()
}
function onPick(event) {
  uploadAll([...event.target.files])
  event.target.value = ''
}
function onDrop(event) {
  over.value = false
  if (event.dataTransfer.files.length) uploadAll([...event.dataTransfer.files])
}

async function copy(f) {
  try {
    await navigator.clipboard.writeText(f.url)
    ui.toast('Indirizzo copiato.')
  } catch {
    ui.toast(f.url)
  }
}

async function remove(f) {
  const ok = await ui.confirm({
    title: 'Eliminare questo file?',
    text: f.staged ? `«${f.name}» non è ancora pubblico: viene tolto dalle modifiche in attesa.` : `«${f.name}» verrà eliminato definitivamente, con le sue misure. Se un contenuto lo usa, lì comparirà un vuoto.`,
    confirmLabel: 'Elimina file',
  })
  if (!ok) return
  try {
    await MediaService.remove(f.name)
    ui.toast('File eliminato.')
    await load()
  } catch (e) {
    ui.toast(e.message)
  }
}

async function optimize() {
  optimizing.value = true
  try {
    const r = await MediaService.optimize()
    ui.toast(r.left ? `Create ${r.made} misure. Restano ${r.left} immagini: premi di nuovo «Ottimizza le immagini».` : r.made ? `Create ${r.made} misure ottimizzate.` : 'Le immagini sono già tutte ottimizzate.')
    await load()
  } catch (e) {
    ui.toast(e.message)
  }
  optimizing.value = false
}
</script>

<template>
  <div class="container">
    <div class="page-head">
      <div>
        <h1>Libreria media</h1>
        <p class="sub"><span class="num">{{ files.length }}</span> file<template v-if="session.isStaging">. I file caricati ora restano in attesa finché non pubblichi.</template></p>
      </div>
      <button v-if="!session.isStaging && limits.can_webp" class="btn btn-white" :disabled="optimizing || session.locked" title="Crea le misure per schermi diversi delle immagini già caricate" @click="optimize">
        {{ optimizing ? 'Ottimizzo…' : 'Ottimizza le immagini' }}
      </button>
    </div>
    <div class="dropzone" :class="{ 'is-over': over }" @dragenter.prevent="over = true" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="onDrop">
      <div>
        <strong>Trascina qui i file, oppure</strong>
        <p class="muted">
          Immagini e PDF fino a {{ formatSize(limits.max_bytes || 0) }}, video MP4 e WebM fino a {{ formatSize(limits.video_max_bytes || 0) }}.
          {{ limits.can_process ? 'Le foto vengono ridotte e ripulite dai dati nascosti (come la posizione GPS).' : '' }}
        </p>
      </div>
      <input ref="input" type="file" multiple hidden :accept="accept" @change="onPick" />
      <button type="button" class="btn btn-primary" :disabled="session.locked" @click="input.click()"><KIcon name="upload" />Scegli file</button>
    </div>
    <div v-if="results.length" class="upload-results" aria-live="polite">
      <div v-for="(r, i) in results" :key="i" class="upload-result" :class="{ success: r.ok === true, error: r.ok === false }"><strong>{{ r.name }}</strong><p>{{ r.status }}</p></div>
    </div>
    <div class="toolbar"><label class="search"><KIcon name="search" /><input v-model="search" type="search" placeholder="Cerca per nome" aria-label="Cerca un file" /></label></div>
    <p v-if="loading" class="loading">Carico la libreria…</p>
    <div v-else-if="shown.length" class="media-grid">
      <article v-for="f in shown" :key="f.url" class="media-card">
        <router-link class="media-art" :to="{ name: 'media-file', params: { name: f.name } }" :aria-label="`Dettagli di ${f.name}`">
          <img v-if="f.kind === 'image'" :src="f.src" alt="" loading="lazy" />
          <video v-else-if="f.kind === 'video'" :src="f.src" muted playsinline preload="metadata"></video>
          <span v-else class="file-placeholder">PDF</span>
          <span v-if="f.staged" class="pill pill-pending media-badge">In attesa</span>
        </router-link>
        <div class="media-name" :title="f.name">{{ f.name }}</div>
        <div class="media-meta">
          <span>{{ f.ext }} · {{ formatSize(f.size) }}</span>
          <span>
            <button type="button" class="icon-button" :aria-label="`Copia l'indirizzo di ${f.name}`" @click="copy(f)"><KIcon name="copy" /></button>
            <button v-if="!session.isStaging || f.staged" type="button" class="icon-button danger" :aria-label="`Elimina ${f.name}`" :disabled="session.locked" @click="remove(f)"><KIcon name="trash" /></button>
          </span>
        </div>
      </article>
    </div>
    <div v-else class="empty-state"><h2>Nessun file da mostrare.</h2><p>Carica il primo file, oppure cambia la ricerca.</p></div>
  </div>
</template>

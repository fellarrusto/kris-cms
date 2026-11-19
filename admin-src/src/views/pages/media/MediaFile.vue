<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import MediaService from '@/services/MediaService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { formatDate, formatSize } from '@/views/pages/media/format'
import { title } from '@/lib/model'

/** Un file della libreria: anteprima, misure per il sito e contenuti che lo usano. */
const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const files = ref([])
const loading = ref(true)
const file = computed(() => files.value.find((f) => f.name === route.params.name) || null)
const dims = ref('')

onMounted(async () => {
  try {
    files.value = await MediaService.list()
  } catch (e) {
    ui.toast(e.message)
  }
  loading.value = false
})

// Dove è usato: i valori dei contenuti che contengono il suo indirizzo
const usedIn = computed(() => {
  if (!file.value) return []
  const needle = file.value.url
  const out = []
  for (const i of site.content) {
    const found = JSON.stringify(i.values || {}).includes(needle) || JSON.stringify(i.values || {}).includes(needle.replace(/%20/g, ' '))
    if (found) out.push({ label: `${site.label(i.type)} › ${title(i, site.fieldsOf(i.type), session.languages[0])}`, to: { name: 'edit', params: { type: i.type, id: i.id } } })
  }
  return out
})

async function copy(url) {
  try {
    await navigator.clipboard.writeText(url)
    ui.toast('Indirizzo copiato.')
  } catch {
    ui.toast(url)
  }
}

async function remove() {
  const extra = usedIn.value.length ? ` Lo usano ${usedIn.value.length === 1 ? 'un contenuto' : usedIn.value.length + ' contenuti'}: lì comparirà un vuoto.` : ''
  if (!(await ui.confirm({ title: 'Eliminare questo file?', text: `«${file.value.name}» verrà eliminato definitivamente, con le sue misure.${extra}`, confirmLabel: 'Elimina file' }))) return
  try {
    await MediaService.remove(file.value.name)
    ui.toast('File eliminato.')
    router.push({ name: 'media' })
  } catch (e) {
    ui.toast(e.message)
  }
}
</script>

<template>
  <div class="container">
    <p v-if="loading" class="loading">Carico il file…</p>
    <div v-else-if="!file" class="empty-state">
      <h2>Questo file non c'è più.</h2>
      <router-link class="btn btn-primary" :to="{ name: 'media' }">Torna alla libreria</router-link>
    </div>
    <template v-else>
      <div class="page-head">
        <div><p class="kicker"><router-link :to="{ name: 'media' }">Libreria media</router-link></p><h1 style="font-size: 26px; word-break: break-all">{{ file.name }}</h1></div>
        <router-link class="btn btn-white" :to="{ name: 'media' }"><KIcon name="back" />Torna alla libreria</router-link>
      </div>
      <div class="media-detail">
        <a class="media-art" :href="file.src" target="_blank" rel="noopener" :aria-label="`Apri ${file.name} a grandezza piena`">
          <img v-if="file.kind === 'image'" :src="file.src" :alt="file.name" @load="dims = `${$event.target.naturalWidth}×${$event.target.naturalHeight}`" />
          <video v-else-if="file.kind === 'video'" :src="file.src" controls muted playsinline preload="metadata"></video>
          <span v-else class="file-placeholder">PDF</span>
        </a>
        <div class="container">
          <div class="box">
            <h2>Informazioni</h2>
            <p class="muted" style="margin-top: 6px">
              {{ file.ext }}<template v-if="dims"> · {{ dims }}</template> · {{ formatSize(file.size) }} · caricato {{ formatDate(file.mtime) }}
              <span v-if="file.staged" class="pill pill-pending">In attesa</span>
            </p>
          </div>
          <div v-if="file.kind === 'image'" class="box">
            <div class="bar"><h2 style="flex: 1">Misure per il sito</h2><span v-if="file.variants.length" class="pill pill-ok">{{ file.variants.length }} {{ file.variants.length === 1 ? 'misura' : 'misure' }}</span></div>
            <p class="muted" style="margin: 4px 0 6px">
              {{ file.variants.length ? 'Create al caricamento: il sito sceglie quella adatta allo schermo.' : file.ext === 'SVG' || file.ext === 'GIF' ? 'Questo formato si usa così com\'è.' : 'Non ci sono ancora misure ottimizzate: le crea «Ottimizza le immagini» nella libreria.' }}
            </p>
            <table v-if="file.variants.length" class="table-list">
              <tbody>
                <tr v-for="v in file.variants" :key="v">
                  <td class="mono">{{ v.match(/-(\d+)w\.webp$/)?.[1] }} px · WebP</td>
                  <td style="text-align: right"><button type="button" class="icon-button" :aria-label="`Copia l'indirizzo di ${v}`" @click="copy('assets/uploads/' + v)"><KIcon name="copy" /></button></td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="box">
            <h2>Dove è usato</h2>
            <ul v-if="usedIn.length" class="staging-problems"><li v-for="u in usedIn" :key="u.label"><router-link :to="u.to">{{ u.label }}</router-link></li></ul>
            <p v-else class="muted" style="margin-top: 6px">Nessun contenuto lo usa. I template del tema potrebbero comunque richiamarlo.</p>
          </div>
          <div class="bar">
            <button type="button" class="btn btn-white" @click="copy(file.url)"><KIcon name="copy" />Copia indirizzo</button>
            <button v-if="!session.isStaging || file.staged" type="button" class="btn btn-white" style="color: var(--danger)" :disabled="session.locked" @click="remove">Elimina</button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

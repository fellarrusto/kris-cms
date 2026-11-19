<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import MediaService from '@/services/MediaService'
import { useUiStore } from '@/stores/ui'

/** Scelta di un file della libreria, con caricamento di uno nuovo. Emette choose(url) o close. */
const props = defineProps({ value: { type: String, default: '' }, accept: { type: String, default: '' } })
const emit = defineEmits(['choose', 'close'])
const ui = useUiStore()

const files = ref([])
const loading = ref(true)
const search = ref('')
const selected = ref(props.value)
const uploading = ref(false)
const input = ref(null)
const dialog = ref(null)

const shown = computed(() => files.value.filter((f) => !search.value || f.name.toLowerCase().includes(search.value.toLowerCase())))

onMounted(async () => {
  dialog.value?.showModal()
  try {
    files.value = await MediaService.list()
  } catch (e) {
    ui.toast(e.message)
  }
  loading.value = false
})

async function upload(event) {
  const file = event.target.files?.[0]
  if (!file) return
  uploading.value = true
  try {
    const result = await MediaService.upload(file)
    files.value = await MediaService.list()
    selected.value = result.url
    ui.toast(result.staged ? 'Caricato fra le modifiche in attesa.' : 'File caricato.')
  } catch (e) {
    ui.toast(e.message)
  }
  uploading.value = false
  event.target.value = ''
}
</script>

<template>
  <dialog ref="dialog" class="dialog media-dialog" aria-labelledby="picker-title" @cancel.prevent="$emit('close')">
    <div class="dialog-head">
      <h2 id="picker-title">Scegli un file</h2>
      <button type="button" class="icon-button" aria-label="Chiudi" @click="$emit('close')"><KIcon name="close" /></button>
    </div>
    <div class="dialog-body">
      <div class="toolbar">
        <label class="search" style="flex: 1"><KIcon name="search" /><input v-model="search" type="search" placeholder="Cerca per nome" aria-label="Cerca nella libreria" /></label>
        <button type="button" class="btn btn-white" :disabled="uploading" @click="input.click()"><KIcon name="upload" />{{ uploading ? 'Carico…' : 'Carica un file' }}</button>
        <input ref="input" type="file" hidden :accept="accept" @change="upload" />
      </div>
      <p v-if="loading" class="muted">Carico la libreria…</p>
      <div v-else-if="shown.length" class="media-grid">
        <button v-for="f in shown" :key="f.url" type="button" class="media-choice" :class="{ selected: selected === f.url }" :aria-pressed="String(selected === f.url)" @click="selected = f.url" @dblclick="emit('choose', f.url)">
          <span class="media-art">
            <img v-if="f.kind === 'image'" :src="f.src" alt="" loading="lazy" />
            <video v-else-if="f.kind === 'video'" :src="f.src" muted playsinline preload="metadata"></video>
            <span v-else class="file-placeholder">PDF</span>
            <span v-if="f.staged" class="pill pill-pending media-badge">In attesa</span>
          </span>
          <span class="media-caption">{{ f.name }}</span>
        </button>
      </div>
      <div v-else class="empty-state"><h3>Nessun file trovato.</h3><p>Carica un file o cambia la ricerca.</p></div>
    </div>
    <div class="dialog-foot">
      <span class="selection-label">{{ selected ? `Selezionato: ${decodeURIComponent(selected.split('/').pop())}` : 'Nessun file selezionato' }}</span>
      <button type="button" class="btn btn-white" @click="$emit('close')">Annulla</button>
      <button type="button" class="btn btn-primary" :disabled="!selected" @click="emit('choose', selected)">Usa questo file</button>
    </div>
  </dialog>
</template>

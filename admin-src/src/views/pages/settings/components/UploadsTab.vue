<script setup>
import { computed, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import SiteService from '@/services/SiteService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const caps = computed(() => session.data?.uploads || {})
const current = site.settings.uploads || {}
const form = ref({ prefix: current.prefix || '', max_width: current.max_width ?? 2560, webp: current.webp !== false })
const busy = ref(false)

async function save() {
  busy.value = true
  try {
    await SiteService.saveUploadSettings(form.value)
    await site.load()
    ui.toast('Impostazioni dei caricamenti salvate.')
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}
</script>

<template>
  <form class="card" @submit.prevent="save">
    <header class="card-header"><span class="collection-icon"><KIcon name="media" /></span><div><h2>Caricamenti</h2><p>Nomi dei file e trattamento delle foto caricate da te o dall'assistente.</p></div></header>
    <div class="card-body collection-info-form">
      <label class="field-label">Prefisso dei nomi dei file<input v-model="form.prefix" class="input" type="text" maxlength="60" placeholder="es. studio-dentistico-romano" /></label>
      <p class="hint">Aiuta la SEO delle immagini: «DSC_1234.jpg» diventa «{{ form.prefix || 'prefisso' }}-…jpg». I nomi automatici delle fotocamere vengono ignorati, quelli scelti da te restano.</p>
      <template v-if="caps.can_process">
        <label class="field-label">Larghezza massima delle foto (pixel)<input v-model.number="form.max_width" class="input" type="number" min="0" max="10000" step="1" /></label>
        <p class="hint">Le foto più larghe vengono ridotte; 0 = mai. Le foto JPEG vengono anche raddrizzate e ripulite dai dati nascosti, come la posizione GPS del telefono.</p>
        <label class="check-row"><input v-model="form.webp" type="checkbox" :disabled="!caps.can_webp" /><span><strong>Converti in WebP</strong><small>{{ caps.can_webp ? 'Foto più leggere, stessa qualità a occhio.' : 'Il PHP di questo hosting non sa scrivere WebP.' }}</small></span></label>
      </template>
      <div v-else class="notice"><strong>Foto caricate così come sono.</strong><p>Su questo hosting manca l'estensione GD di PHP: le foto non vengono ridotte né ripulite dai dati nascosti. Chiedi all'hosting di attivarla.</p></div>
      <button class="btn btn-primary btn-small" :disabled="busy || session.locked">Salva</button>
    </div>
  </form>
</template>

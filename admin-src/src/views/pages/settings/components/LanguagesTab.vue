<script setup>
import { ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import SiteService from '@/services/SiteService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const all = Object.keys(session.data?.language_names || {})
const chosen = ref([...session.languages])
const main = ref(session.languages[0])
const busy = ref(false)

async function save() {
  if (!chosen.value.length) return ui.toast('Scegli almeno una lingua.')
  if (!chosen.value.includes(main.value)) main.value = chosen.value[0]
  busy.value = true
  try {
    await SiteService.saveLanguages(chosen.value, main.value)
    await session.load()
    await site.load()
    ui.toast('Lingue salvate. Le traduzioni delle lingue disattivate restano conservate.')
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}
</script>

<template>
  <form class="card" @submit.prevent="save">
    <header class="card-header"><span class="collection-icon"><KIcon name="language" /></span><div><h2>Lingue del sito</h2><p>Le lingue in cui si scrivono i contenuti. La principale è quella degli indirizzi senza prefisso e dei testi che mancano nelle altre.</p></div></header>
    <div class="card-body">
      <label v-for="code in all" :key="code" class="setting-row">
        <input v-model="chosen" type="checkbox" :value="code" :disabled="session.isStaging" />
        <span><strong>{{ session.languageName(code) }}</strong><small>{{ code.toUpperCase() }}{{ code === session.languages[0] ? ' · principale' : '' }}</small></span>
      </label>
      <label class="field-label">Lingua principale
        <select v-model="main" class="input" :disabled="session.isStaging"><option v-for="code in chosen" :key="code" :value="code">{{ session.languageName(code) }}</option></select>
      </label>
      <div class="notice"><strong>I testi restano al sicuro.</strong><p>Disattivare una lingua la toglie dai moduli e dal sito, ma conserva le traduzioni già scritte.</p></div>
      <p v-if="session.isStaging" class="hint">Le lingue si cambiano sul sito pubblico.</p>
      <button class="btn btn-primary btn-small" style="align-self: flex-start" :disabled="busy || session.isStaging || session.locked">Salva le lingue</button>
    </div>
  </form>
</template>

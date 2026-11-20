<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import { useDirty } from '@/components/dirty'
import FieldInput from '@/views/pages/edit/components/FieldInput.vue'
import PluginService from '@/services/PluginService'
import { useSessionStore } from '@/stores/session'
import { useUiStore } from '@/stores/ui'

/**
 * Le impostazioni di un plugin: i campi dichiarati nel manifest (settings), con gli
 * stessi controlli della modifica di un contenuto. Si salvano sul sito pubblico.
 */
const route = useRoute()
const session = useSessionStore()
const ui = useUiStore()
const { setDirty } = useDirty()

const name = computed(() => String(route.params.name))
const title = ref('')
const fields = ref([])
const saved = ref({})
const values = ref({})
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const dirty = computed(() => JSON.stringify(values.value) !== JSON.stringify(saved.value))
watch(dirty, (d) => setDirty(d))
onBeforeUnmount(() => setDirty(false))

const stagedNames = computed(() => new Set(session.data?.staged_uploads || []))
const accept = computed(() => (session.data?.uploads?.accept || []).map((e) => '.' + e).join(','))
const label = (f) => f.description || f.name

async function load() {
  loading.value = true
  error.value = ''
  try {
    const r = await PluginService.settings(name.value)
    title.value = r.title
    fields.value = r.fields
    const v = {}
    for (const f of r.fields) {
      const raw = r.values?.[f.name]
      v[f.name] = f.localized ? Object.fromEntries(session.languages.map((l) => [l, raw?.[l] ?? empty(f)])) : (raw ?? empty(f))
    }
    saved.value = JSON.parse(JSON.stringify(v))
    values.value = v
  } catch (e) {
    error.value = e.message
  }
  loading.value = false
}
const empty = (f) => (f.type === 'boolean' ? false : f.type === 'select' && f.multiple ? [] : '')
watch(name, load, { immediate: true })

async function save() {
  saving.value = true
  try {
    await PluginService.saveSettings(name.value, values.value)
    saved.value = JSON.parse(JSON.stringify(values.value))
    ui.toast('Impostazioni del plugin salvate.')
  } catch (e) {
    ui.toast(e.message)
  }
  saving.value = false
}
const discard = () => (values.value = JSON.parse(JSON.stringify(saved.value)))
</script>

<template>
  <div class="container">
    <div class="page-head">
      <div>
        <p class="eyebrow">Plugin</p>
        <h1>{{ title || 'Impostazioni del plugin' }}</h1>
        <p class="sub">Le impostazioni valgono subito sul sito pubblico, anche in modalità «In attesa».</p>
      </div>
      <router-link class="btn btn-white btn-small" :to="{ name: 'settings', params: { tab: 'plugin' } }"><KIcon name="puzzle" />Tutti i plugin</router-link>
    </div>
    <div v-if="error" class="alert alert-error" role="alert"><KIcon name="warning" /><p>{{ error }}</p></div>
    <p v-else-if="loading" class="muted">Carico…</p>
    <form v-else class="panel" @submit.prevent="save">
      <div v-for="(f, i) in fields" :key="f.name" class="field">
        <template v-if="f.localized">
          <div class="field-head"><span>{{ label(f) }}</span><span class="scope">Per lingua</span><span class="field-tech">{{ f.name }}</span></div>
          <FieldInput
            v-for="lang in session.languages"
            :id="`p-${i}-${lang}`"
            :key="lang"
            :def="f"
            :label="`${label(f)} · ${session.languageName(lang)}`"
            :model-value="values[f.name][lang]"
            :staged-names="stagedNames"
            :accept="accept"
            @update:model-value="values[f.name][lang] = $event"
          />
        </template>
        <template v-else>
          <div class="field-head">
            <label v-if="['text', 'select', 'boolean'].includes(f.type) && !f.multiple" :for="`p-${i}`">{{ label(f) }}</label><span v-else>{{ label(f) }}</span>
            <span class="field-tech">{{ f.name }}</span>
          </div>
          <FieldInput :id="`p-${i}`" :def="f" :label="label(f)" :model-value="values[f.name]" :staged-names="stagedNames" :accept="accept" @update:model-value="values[f.name] = $event" />
        </template>
      </div>
      <div class="savebar" :class="{ 'is-dirty': dirty, 'is-saving': saving }" role="status">
        <div class="savebar-text">
          <strong>{{ saving ? 'Salvataggio…' : dirty ? 'Modifiche non salvate' : 'Tutte le modifiche sono salvate' }}</strong>
          <small>Salvando, il plugin usa subito i valori nuovi.</small>
        </div>
        <button v-if="dirty" type="button" class="btn" :disabled="saving" @click="discard">Annulla modifiche</button>
        <button type="submit" class="btn" data-save-button :disabled="saving || !dirty || session.locked">Salva</button>
      </div>
    </form>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import PageHead from '@/components/PageHead.vue'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { isNewestFirst, isSingle } from '@/lib/model'

/** I tipi di contenuto del sito e la creazione di uno nuovo. */
const router = useRouter()
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const label = ref('')
const name = ref('')
const single = ref(false)
let touched = false
// Il nome tecnico segue quello visibile finché non lo si cambia a mano
watch(label, (value) => {
  if (touched) return
  name.value = value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^[^a-z]+|_+$/g, '')
    .slice(0, 40)
})

async function create() {
  const ops = [{ op: 'create_collection', name: name.value, fields: [] }]
  if (label.value.trim() || single.value) ops.push({ op: 'set_collection_info', collection: name.value, label: label.value.trim(), single: single.value })
  try {
    await site.apply(ops)
    router.push({ name: 'structure-type', params: { type: name.value } })
  } catch (e) {
    ui.toast(e.message)
  }
}
</script>

<template>
  <div class="container">
    <PageHead>
      <div><h1>Struttura</h1><p class="sub">I tipi di contenuto del sito e i loro campi. Qui si decide cosa si può modificare, non i testi.</p></div>
    </PageHead>
    <div v-if="session.isStaging" class="notice notice-warning"><strong>Solo sul sito pubblico.</strong><p>La struttura si cambia sul sito pubblico: passa a «Sito pubblico» per modificarla.</p></div>
    <div class="structure-cols">
      <div class="rows">
        <div v-for="t in site.userTypes" :key="t.name" class="row">
          <router-link class="row-main" :to="{ name: 'structure-type', params: { type: t.name } }">
            <span class="ic"><KIcon :name="isSingle(site.schema, t.name) ? 'page' : 'list'" /></span>
            <span class="row-text">
              <span class="row-title">{{ site.label(t.name) }}</span>
              <span class="row-sub mono">{{ t.name }} · {{ (t.fields || []).length }} {{ (t.fields || []).length === 1 ? 'campo' : 'campi' }} · {{ site.counts[t.name] || 0 }} {{ site.counts[t.name] === 1 ? 'contenuto' : 'contenuti' }}</span>
            </span>
          </router-link>
          <span class="tag">{{ isSingle(site.schema, t.name) ? 'Contenuto unico' : isNewestFirst(site.schema, t.name) ? 'Articoli' : 'Raccolta' }}</span>
        </div>
        <p v-if="!site.userTypes.length" class="muted" style="padding: 16px">Ancora nessun tipo: creane uno qui accanto.</p>
      </div>
      <form class="box collection-info-form" @submit.prevent="create">
        <h2>Nuova raccolta</h2>
        <label class="field-label">Nome visibile<input v-model="label" class="input" type="text" maxlength="60" placeholder="Es. Servizi" /></label>
        <label class="field-label">Nome tecnico<input v-model="name" class="input mono" type="text" pattern="[a-z][a-z0-9_]*" placeholder="servizi" required @input="touched = true" /></label>
        <p class="hint">Lo usano i template. Lettere minuscole, numeri e _; non si cambia dopo.</p>
        <label class="check-row"><input v-model="single" type="checkbox" /><span><strong>Contenuto unico</strong><small>Per una pagina o un blocco condiviso: si apre direttamente la modifica.</small></span></label>
        <button class="btn btn-primary" :disabled="session.isStaging || session.locked"><KIcon name="plus" />Crea e scegli i campi</button>
      </form>
    </div>
  </div>
</template>

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import SiteService from '@/services/SiteService'
import { useSessionStore } from '@/stores/session'
import * as model from '@/lib/model'

/**
 * Struttura, contenuti e impostazioni del sito nella modalità in uso. Ogni scrittura
 * è un elenco di operazioni: dopo la conferma del server i dati si rileggono.
 */
export const useSiteStore = defineStore('site', () => {
  const schema = ref({ types: {}, pages: {} })
  const content = ref([])
  const settings = ref({})
  const loaded = ref(false)

  const userTypes = computed(() => model.userTypes(schema.value))
  const counts = computed(() => content.value.reduce((acc, i) => ({ ...acc, [i.type]: (acc[i.type] || 0) + 1 }), {}))

  async function load() {
    const data = await SiteService.get()
    schema.value = data.schema
    content.value = data.content
    settings.value = data.settings
    loaded.value = true
  }

  /** Applica operazioni nella modalità in uso; restituisce quelle completate e gli avvisi. */
  async function apply(ops) {
    const result = await SiteService.apply(ops)
    useSessionStore().setStaging(result.staging)
    await load()
    return result
  }

  const type = (name) => model.typeOf(schema.value, name)
  const label = (name) => model.typeLabel(schema.value, name)
  const items = (name) => model.items(content.value, name)
  const fieldsOf = (name, lists = []) => model.fields(schema.value, name, lists)

  return { schema, content, settings, loaded, userTypes, counts, load, apply, type, label, items, fieldsOf }
})

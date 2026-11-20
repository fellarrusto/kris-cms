<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import Edit from '@/views/pages/edit/Edit.vue'
import { useSiteStore } from '@/stores/site'

/** Le pagine che Kris mostra al posto del sito: la 404 e i lavori in corso, una scheda ciascuna. */
const route = useRoute()
const site = useSiteStore()

const TABS = [
  { key: '404', type: 'kris_notfound', label: 'Pagina 404', hint: 'Indirizzi che non esistono', icon: 'notfound' },
  { key: 'lavori', type: 'kris_wip', label: 'Lavori in corso', hint: 'Il sito chiuso ai visitatori', icon: 'wip' },
]
const tabs = computed(() => TABS.filter((t) => site.type(t.type)))
const current = computed(() => tabs.value.find((t) => t.key === route.params.tab) || tabs.value[0])
const wipOn = computed(() => site.content.find((i) => i.type === 'kris_wip')?.values?.enabled === true)
</script>

<template>
  <div class="container">
    <div class="page-head">
      <div>
        <h1>Pagine di sistema</h1>
        <p class="sub">Le pagine che Kris mostra al posto di quelle del sito.</p>
      </div>
    </div>
    <div v-if="!tabs.length" class="empty-state">
      <h2>Questo sito non ha ancora le pagine di sistema.</h2>
      <p>Arrivano con l'aggiornamento del formato dei contenuti.</p>
    </div>
    <div v-else class="settings-layout">
      <nav class="settings-nav" aria-label="Pagine di sistema">
        <router-link
          v-for="t in tabs"
          :key="t.key"
          :to="{ name: 'system', params: { tab: t.key } }"
          :class="{ active: t.key === current.key }"
          :aria-current="t.key === current.key ? 'page' : undefined"
        >
          <KIcon :name="t.icon" /><span
            ><strong>{{ t.label }}</strong
            ><small>{{ t.key === 'lavori' && wipOn ? 'Attivi: i visitatori non vedono il sito' : t.hint }}</small></span
          >
        </router-link>
      </nav>
      <div class="settings-content"><Edit :key="current.type" :fixed-type="current.type" /></div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import ThemeToggle from '@/components/ThemeToggle.vue'
import { MODES } from '@/config'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { RESERVED_VIEWS } from '@/lib/model'
import { useDirty } from '@/components/dirty'

defineProps({ menuOpen: Boolean })
defineEmits(['toggle-menu'])

const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()
const route = useRoute()
const dirty = useDirty()

const SECTIONS = {
  media: 'Libreria media',
  'media-file': 'Libreria media',
  publish: 'Da pubblicare',
  structure: 'Struttura',
  'structure-type': 'Struttura',
  addresses: 'Indirizzi',
  system: 'Pagine di sistema',
  settings: 'Impostazioni',
}
// Titolo della barra per le schermate senza PageHead (Impostazioni, Indirizzi, Pagine di sistema…)
const fallbackTitle = computed(() => {
  const type = String(route.params.type || '')
  if (RESERVED_VIEWS[type]) return RESERVED_VIEWS[type].label
  if (route.name === 'plugin-view' || route.name === 'plugin-settings')
    return session.data?.plugins?.active?.find((x) => x.name === route.params.name)?.title || String(route.params.name)
  if (type && site.type(type)) return site.label(type)
  return SECTIONS[route.name] || 'Contenuti'
})
const siteUrl = computed(
  () => (session.data?.site_url || '../') + (session.isStaging ? 'index.php?kris_preview=1' : 'index.php?kris_preview=0')
)

async function switchMode(mode) {
  if (mode === session.mode) return
  if (!(await dirty.allowLeave())) return
  try {
    await session.setMode(mode)
  } catch (e) {
    ui.toast(e.message)
  }
}
</script>

<template>
  <header class="top">
    <button
      type="button"
      class="icon-button menu-toggle"
      aria-controls="sidebar"
      :aria-expanded="String(menuOpen)"
      aria-label="Apri il menu"
      @click="$emit('toggle-menu')"
    >
      <KIcon name="menu" />
    </button>
    <div class="top-title">
      <div id="page-title" class="page-title"></div>
      <h1 class="top-fallback">{{ fallbackTitle }}</h1>
    </div>
    <div class="top-tools">
      <div id="page-actions" class="page-actions"></div>
      <div class="seg mode-switch" role="group" aria-label="Dove salvano le modifiche">
        <button type="button" value="live" :aria-pressed="String(!session.isStaging)" @click="switchMode(MODES.LIVE)">
          <span class="dot" aria-hidden="true"></span>Sito pubblico
        </button>
        <button type="button" value="staging" :aria-pressed="String(session.isStaging)" @click="switchMode(MODES.STAGING)">
          In attesa<span v-if="session.stagingCount" class="seg-count">{{ session.stagingCount }}</span>
        </button>
      </div>
      <a :href="siteUrl" target="_blank" rel="noopener" class="btn btn-white"
        >{{ session.isStaging ? 'Anteprima' : 'Apri il sito' }} <KIcon name="external"
      /></a>
      <ThemeToggle />
    </div>
  </header>
</template>

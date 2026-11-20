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
const crumb = computed(() => {
  const type = String(route.params.type || '')
  if (RESERVED_VIEWS[type]) return [{ label: 'Sito' }, { label: RESERVED_VIEWS[type].label }]
  if (route.name === 'plugin-view' || route.name === 'plugin-settings') {
    const p = session.data?.plugins?.active?.find((x) => x.name === route.params.name)
    return [{ label: 'Plugin', to: { name: 'settings', params: { tab: 'plugin' } } }, { label: p?.title || String(route.params.name) }]
  }
  const section = SECTIONS[route.name]
  const out = [
    {
      label: section || 'Contenuti',
      to: {
        name: section ? (route.name === 'structure-type' ? 'structure' : route.name === 'media-file' ? 'media' : route.name) : 'dashboard',
      },
    },
  ]
  if (type && site.type(type))
    out.push({ label: site.label(type), to: route.name === 'structure-type' ? undefined : { name: 'collection', params: { type } } })
  return out
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
      <nav v-if="crumb.length > 1" class="crumb" aria-label="Posizione">
        <template v-for="(c, i) in crumb.slice(0, -1)" :key="i">
          <span v-if="i" aria-hidden="true">/</span>
          <router-link v-if="c.to" :to="c.to">{{ c.label }}</router-link>
          <span v-else>{{ c.label }}</span>
        </template>
      </nav>
      <div id="page-title" class="page-title"></div>
      <h1 class="top-fallback">{{ crumb[crumb.length - 1].label }}</h1>
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

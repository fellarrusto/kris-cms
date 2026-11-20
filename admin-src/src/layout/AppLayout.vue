<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import AppSidebar from '@/layout/AppSidebar.vue'
import AppTopbar from '@/layout/AppTopbar.vue'
import SystemAlerts from '@/layout/SystemAlerts.vue'
import KIcon from '@/components/KIcon.vue'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()
const route = useRoute()
const menuOpen = ref(false)
// Lavori in corso accesi: una striscia sottile in cima, l unico promemoria
const wipOn = computed(() => site.content.find((i) => i.type === 'kris_wip')?.values?.enabled === true)

async function loadSite() {
  try {
    await site.load()
  } catch (e) {
    ui.toast(e.message)
  }
}

onMounted(loadSite)
// Cambiando modalità cambiano i dati: il sito pubblico o le modifiche in attesa
watch(() => session.mode, loadSite)
watch(
  () => route.fullPath,
  () => (menuOpen.value = false)
)
</script>

<template>
  <a class="skip-link" href="#main">Vai al contenuto</a>
  <div v-if="wipOn" class="wip-bar" role="status">
    <span class="wip-tag">Lavori in corso</span>
    <p>I visitatori vedono la pagina di lavorazione; tu il sito, perché hai l'editor aperto.</p>
    <a :href="`${session.data?.site_url || '../'}index.php?kris_wip_preview=1`" target="_blank" rel="noopener">Vedi la pagina</a>
    <router-link :to="{ name: 'system', params: { tab: 'lavori' } }">Spegni</router-link>
  </div>
  <div v-if="session.isStaging" class="pending-bar" role="status">
    <KIcon name="clock" />
    <p>
      <strong>Stai lavorando in attesa.</strong> Il sito pubblico non cambia finché non pubblichi.
      <template v-if="session.stagingCount"
        >{{ session.stagingCount }} {{ session.stagingCount === 1 ? 'modifica pronta' : 'modifiche pronte' }}.</template
      >
    </p>
    <router-link class="btn btn-small" :to="{ name: 'publish' }">Rivedi e pubblica</router-link>
  </div>
  <div class="shell" :class="{ 'is-pending': session.isStaging }">
    <AppTopbar :menu-open="menuOpen" @toggle-menu="menuOpen = !menuOpen" />
    <AppSidebar :open="menuOpen" />
    <div v-if="menuOpen" class="side-scrim" @click="menuOpen = false"></div>
    <main id="main" class="main" tabindex="-1">
      <SystemAlerts />
      <div v-if="!site.loaded" class="loading">Carico i contenuti…</div>
      <router-view v-else :key="session.mode" />
    </main>
  </div>
</template>

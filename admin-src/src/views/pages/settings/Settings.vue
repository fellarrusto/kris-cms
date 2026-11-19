<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import { useSessionStore } from '@/stores/session'
import LanguagesTab from '@/views/pages/settings/components/LanguagesTab.vue'
import UploadsTab from '@/views/pages/settings/components/UploadsTab.vue'
import AccountTab from '@/views/pages/settings/components/AccountTab.vue'
import UsersTab from '@/views/pages/settings/components/UsersTab.vue'
import AgentTab from '@/views/pages/settings/components/AgentTab.vue'
import StoreTab from '@/views/pages/settings/components/StoreTab.vue'
import VersionTab from '@/views/pages/settings/components/VersionTab.vue'
import PluginsTab from '@/views/pages/settings/components/PluginsTab.vue'

const route = useRoute()
const session = useSessionStore()

const TABS = [
  { key: 'lingue', label: 'Lingue', hint: 'Lingue del sito', icon: 'language', component: LanguagesTab },
  { key: 'caricamenti', label: 'Caricamenti', hint: 'Nomi e foto', icon: 'media', component: UploadsTab },
  { key: 'account', label: 'Account', hint: 'Email e password', icon: 'user', component: AccountTab },
  { key: 'utenti', label: 'Utenti', hint: 'Chi accede all’editor', icon: 'user', component: UsersTab, admin: true },
  { key: 'assistente', label: 'Assistente AI', hint: 'Kris Agent', icon: 'agent', component: AgentTab },
  { key: 'plugin', label: 'Plugin', hint: 'Funzioni in più', icon: 'puzzle', component: PluginsTab, plugins: true },
  { key: 'archivio', label: 'Archivio dei dati', hint: 'File o database', icon: 'code', component: StoreTab },
  { key: 'versione', label: 'Versione di Kris', hint: 'Aggiornamenti', icon: 'version', component: VersionTab },
]
const tabs = computed(() => TABS.filter((t) => (!t.admin || session.isAdmin) && (!t.plugins || session.data?.plugins?.enabled)))
const current = computed(() => tabs.value.find((t) => t.key === route.params.tab) || tabs.value[0])
</script>

<template>
  <div class="container">
    <div class="page-head"><div><h1>Impostazioni</h1></div></div>
    <div class="settings-layout">
      <nav class="settings-nav" aria-label="Sezioni delle impostazioni">
        <router-link v-for="t in tabs" :key="t.key" :to="{ name: 'settings', params: { tab: t.key } }" :class="{ active: t.key === current.key }" :aria-current="t.key === current.key ? 'page' : undefined">
          <KIcon :name="t.icon" /><span><strong>{{ t.label }}</strong><small>{{ t.hint }}</small></span>
        </router-link>
      </nav>
      <div class="settings-content"><component :is="current.component" /></div>
    </div>
  </div>
</template>

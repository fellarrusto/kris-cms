<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import { devItems } from '@/router/menu'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { isNewestFirst } from '@/lib/model'

defineProps({ open: Boolean })

const session = useSessionStore()
const site = useSiteStore()
const route = useRoute()

const newest = computed(() => site.userTypes.filter((t) => isNewestFirst(site.schema, t.name)))
const hasSystem = computed(() => !!(site.type('kris_notfound') || site.type('kris_wip')))
const wipOn = computed(() => site.content.find((i) => i.type === 'kris_wip')?.values?.enabled === true)
const siteName = computed(() => session.data?.site_name || 'Il tuo sito')
// Plugin accesi: le loro schermate, o le impostazioni se non ne hanno
const pluginItems = computed(() =>
  (session.data?.plugins?.active || []).flatMap((p) => {
    const views = p.views
      .filter((v) => v.nav)
      .map((v) => ({
        key: `${p.name}/${v.name}`,
        label: v.title,
        icon: v.icon,
        to: { name: 'plugin-view', params: { name: p.name, view: v.name } },
      }))
    return views.length || !p.settings
      ? views
      : [{ key: p.name, label: p.title, icon: 'puzzle', to: { name: 'plugin-settings', params: { name: p.name } } }]
  })
)
const typeParam = computed(() => String(route.params.type || ''))
const contentsActive = computed(
  () =>
    ['dashboard', 'collection', 'edit'].includes(route.name) &&
    !typeParam.value.startsWith('kris_') &&
    !newest.value.some((t) => t.name === typeParam.value)
)
</script>

<template>
  <nav id="sidebar" class="side" :class="{ 'is-open': open }" aria-label="Navigazione principale">
    <router-link class="brand" :to="{ name: 'dashboard' }"
      ><span class="brand-mark" aria-hidden="true">K</span><span class="brand-name">{{ siteName }}</span></router-link
    >
    <div class="nav-group">
      <router-link class="nav-item" :class="{ 'is-active': contentsActive }" :to="{ name: 'dashboard' }"
        ><KIcon name="grid" /><span class="nav-text">Contenuti</span></router-link
      >
      <router-link class="nav-item" active-class="is-active" :to="{ name: 'media' }"
        ><KIcon name="media" /><span class="nav-text">Libreria media</span></router-link
      >
    </div>
    <div v-if="session.data?.staging?.visible" class="nav-group">
      <router-link
        class="nav-item nav-publish"
        :class="{ 'has-changes': session.stagingCount }"
        active-class="is-active"
        :to="{ name: 'publish' }"
      >
        <KIcon name="publish" /><span class="nav-text">Da pubblicare</span
        ><span v-if="session.stagingCount" class="nav-count">{{ session.stagingCount }}</span>
      </router-link>
    </div>
    <div v-if="newest.length" class="nav-group">
      <p class="nav-label">Raccolte</p>
      <router-link
        v-for="t in newest"
        :key="t.name"
        class="nav-item"
        :class="{ 'is-active': typeParam === t.name }"
        :to="{ name: 'collection', params: { type: t.name } }"
      >
        <KIcon name="posts" /><span class="nav-text">{{ site.label(t.name) }}</span
        ><span class="nav-count">{{ site.counts[t.name] || 0 }}</span>
      </router-link>
    </div>
    <div v-if="hasSystem" class="nav-group">
      <p class="nav-label">Sito</p>
      <router-link class="nav-item" active-class="is-active" :to="{ name: 'system' }">
        <KIcon name="wip" /><span class="nav-text">Pagine di sistema</span><span v-if="wipOn" class="nav-count">Attivi</span>
      </router-link>
    </div>
    <div v-if="pluginItems.length" class="nav-group">
      <p class="nav-label">Plugin installati</p>
      <router-link v-for="i in pluginItems" :key="i.key" class="nav-item" active-class="is-active" :to="i.to"
        ><KIcon :name="i.icon" /><span class="nav-text">{{ i.label }}</span></router-link
      >
    </div>
    <div class="nav-group">
      <p class="nav-label">Sviluppo</p>
      <router-link v-for="i in devItems" :key="i.label" class="nav-item" active-class="is-active" :to="i.to"
        ><KIcon :name="i.icon" /><span class="nav-text">{{ i.label }}</span></router-link
      >
    </div>
    <div class="nav-foot">
      <router-link class="nav-item" active-class="is-active" :to="{ name: 'settings' }"
        ><KIcon name="settings" /><span class="nav-text">Impostazioni</span></router-link
      >
      <a class="nav-item" href="?logout=1"
        ><KIcon name="logout" /><span class="nav-text">Esci</span><span class="nav-count">{{ session.user?.name }}</span></a
      >
    </div>
  </nav>
</template>

import { createRouter, createWebHashHistory } from 'vue-router'
import AppLayout from '@/layout/AppLayout.vue'
import { useSessionStore } from '@/stores/session'
import { useDirty } from '@/components/dirty'
import { RESERVED_HOME } from '@/router/menu'

/**
 * Indirizzi con # (kris-admin/#/...): sugli hosting non servono regole di riscrittura.
 * L'accesso lo fa la pagina PHP: qui la sessione c'è sempre. meta.admin protegge le pagine
 * degli amministratori (l'API lo controlla comunque).
 */
const router = createRouter({
  history: createWebHashHistory(),
  scrollBehavior: (to, from, saved) => saved || (to.hash ? { el: to.hash } : { top: 0 }),
  routes: [
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', name: 'dashboard', component: () => import('@/views/pages/dashboard/Dashboard.vue') },
        { path: 'raccolta/:type', name: 'collection', component: () => import('@/views/pages/collection/Collection.vue') },
        { path: 'modifica/:type/:id/:path*', name: 'edit', component: () => import('@/views/pages/edit/Edit.vue') },
        { path: 'libreria', name: 'media', component: () => import('@/views/pages/media/Media.vue') },
        { path: 'libreria/:name', name: 'media-file', component: () => import('@/views/pages/media/MediaFile.vue') },
        { path: 'da-pubblicare', name: 'publish', component: () => import('@/views/pages/publish/Publish.vue') },
        { path: 'struttura', name: 'structure', component: () => import('@/views/pages/structure/Structure.vue') },
        { path: 'struttura/:type', name: 'structure-type', component: () => import('@/views/pages/structure/StructureType.vue') },
        { path: 'indirizzi/:tab?', name: 'addresses', component: () => import('@/views/pages/addresses/Addresses.vue') },
        { path: 'sistema/:tab?', name: 'system', component: () => import('@/views/pages/system/SystemPages.vue') },
        { path: 'impostazioni/:tab?', name: 'settings', component: () => import('@/views/pages/settings/Settings.vue') },
        { path: 'plugin/:name', name: 'plugin-settings', component: () => import('@/views/pages/plugins/PluginSettings.vue') },
        { path: 'plugin/:name/:view', name: 'plugin-view', component: () => import('@/views/pages/plugins/PluginView.vue') },
      ],
    },
    { path: '/:catchAll(.*)*', redirect: '/' },
  ],
})

router.beforeEach(async (to, from) => {
  // Lasciando una pagina con modifiche non salvate si chiede prima
  if (from.matched.length && to.path !== from.path && !(await useDirty().allowLeave())) return false
  const session = useSessionStore()
  if (!session.data) await session.load()
  if (to.meta.admin && !session.isAdmin) return { name: 'dashboard' }
  // Le sezioni di Kris si modificano nelle loro schede (i vecchi link #/modifica/kris_… portano lì)
  if (to.name === 'edit' && RESERVED_HOME[to.params.type] && !(to.params.path || []).length) return RESERVED_HOME[to.params.type]
  return true
})

export default router

<script setup>
import { computed, markRaw, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import PageHead from '@/components/PageHead.vue'
import { pluginView } from '@/lib/plugins'
import { useSessionStore } from '@/stores/session'

/** Una schermata di un plugin: il componente del suo modulo, dentro il layout dell'editor. */
const route = useRoute()
const session = useSessionStore()

const plugin = computed(() => session.data?.plugins?.active?.find((p) => p.name === route.params.name) || null)
const view = computed(() => plugin.value?.views?.find((v) => v.name === route.params.view) || null)
const component = ref(null)
const error = ref('')

watch(
  [plugin, view],
  async () => {
    component.value = null
    error.value = ''
    if (!plugin.value || !view.value) {
      error.value = 'Questo plugin non è acceso o non ha questa schermata.'
      return
    }
    try {
      component.value = markRaw(await pluginView(plugin.value, view.value.name))
    } catch (e) {
      error.value = `La schermata del plugin non si carica: ${e.message}`
    }
  },
  { immediate: true }
)
</script>

<template>
  <div class="container">
    <PageHead>
      <div>
        <h1>{{ view?.title || 'Plugin' }}</h1>
      </div>
      <template #actions>
        <router-link v-if="plugin?.settings" class="btn btn-white btn-small" :to="{ name: 'plugin-settings', params: { name: plugin.name } }"><KIcon name="settings" />Impostazioni</router-link>
      </template>
    </PageHead>
    <div v-if="error" class="alert alert-error" role="alert"><KIcon name="warning" /><p>{{ error }}</p></div>
    <p v-else-if="!component" class="muted">Carico…</p>
    <component :is="component" v-else :plugin="plugin" :view="view.name" />
  </div>
</template>

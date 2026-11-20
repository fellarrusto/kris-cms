<script setup>
import { markRaw, onMounted, ref } from 'vue'
import { pluginView } from '@/lib/plugins'

/** Il riquadro di un plugin nella dashboard: la sua schermata «dashboard», piccola. */
const props = defineProps({ plugin: { type: Object, required: true } })
const component = ref(null)
const error = ref('')

onMounted(async () => {
  try {
    component.value = markRaw(await pluginView(props.plugin, props.plugin.dashboard))
  } catch (e) {
    error.value = e.message
  }
})
</script>

<template>
  <section class="box">
    <h2>{{ plugin.title }}</h2>
    <p v-if="error" class="muted">Il riquadro non si carica: {{ error }}</p>
    <component :is="component" v-else-if="component" :plugin="plugin" :view="plugin.dashboard" compact />
    <p v-else class="muted">Carico…</p>
  </section>
</template>

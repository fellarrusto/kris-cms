<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'

/**
 * Tema dell'editor: automatico (segue il sistema), scuro o chiaro. La scelta resta in
 * questo browser (localStorage, chiave kris-theme) e la applica index.php prima di caricare l'app.
 */
const THEMES = {
  '': { label: 'automatico', icon: 'monitor', next: 'dark' },
  dark: { label: 'scuro', icon: 'moon', next: 'light' },
  light: { label: 'chiaro', icon: 'sun', next: '' },
}
const theme = ref('')
onMounted(() => (theme.value = document.documentElement.dataset.theme || ''))
const current = computed(() => THEMES[theme.value] || THEMES[''])

function toggle() {
  const next = current.value.next
  theme.value = next
  if (next) document.documentElement.dataset.theme = next
  else delete document.documentElement.dataset.theme
  try {
    if (next) localStorage.setItem('kris-theme', next)
    else localStorage.removeItem('kris-theme')
  } catch {
    // Senza localStorage la scelta vale finché la pagina resta aperta
  }
}
</script>

<template>
  <button
    type="button"
    class="round-button"
    :title="`Tema: ${current.label}`"
    :aria-label="`Tema: ${current.label}. Cambia tema`"
    @click="toggle"
  >
    <KIcon :name="current.icon" />
  </button>
</template>

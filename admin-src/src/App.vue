<script setup>
import { useUiStore } from '@/stores/ui'

const ui = useUiStore()
</script>

<template>
  <router-view />
  <div v-if="ui.dialog" class="modal-backdrop" @keydown.esc="ui.dialog.resolve(null)">
    <div class="dialog confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-text">
      <div class="dialog-head"><h2 id="confirm-title">{{ ui.dialog.title }}</h2></div>
      <div class="dialog-body"><p id="confirm-text">{{ ui.dialog.text }}</p></div>
      <div class="dialog-foot">
        <button
          v-for="(option, i) in ui.dialog.options"
          :key="i"
          type="button"
          class="btn"
          :class="option.kind || 'btn-white'"
          v-focus="i === ui.dialog.options.length - 1"
          @click="ui.dialog.resolve(option.value)"
        >
          {{ option.label }}
        </button>
      </div>
    </div>
  </div>
  <div v-if="ui.toastText" class="toast" role="status">{{ ui.toastText }}</div>
</template>

<script>
// Il pulsante principale della conferma riceve il focus all'apertura
export default {
  directives: { focus: { mounted: (el, binding) => binding.value && el.focus() } },
}
</script>

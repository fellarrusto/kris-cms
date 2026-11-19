import { defineStore } from 'pinia'
import { ref } from 'vue'

/** Avvisi brevi (toast) e richieste di conferma, mostrati da App.vue. */
export const useUiStore = defineStore('ui', () => {
  const toastText = ref('')
  const dialog = ref(null)
  let timer

  function toast(text) {
    clearTimeout(timer)
    toastText.value = text
    timer = setTimeout(() => (toastText.value = ''), 6000)
  }

  /**
   * Chiede una scelta: confirm({ title, text, options: [{ label, value, kind }] }) → il value scelto, o null.
   * Con solo title/text/confirmLabel: Annulla o conferma (true/false).
   */
  function confirm({ title, text, confirmLabel = 'Conferma', danger = true, options }) {
    return new Promise((resolve) => {
      dialog.value = {
        title,
        text,
        options: options || [
          { label: 'Annulla', value: false },
          { label: confirmLabel, value: true, kind: danger ? 'btn-danger' : 'btn-primary' },
        ],
        resolve: (value) => {
          dialog.value = null
          resolve(value)
        },
      }
    })
  }

  return { toastText, dialog, toast, confirm }
})

import { ref } from 'vue'
import { useUiStore } from '@/stores/ui'

/**
 * Modifiche non salvate della pagina aperta. La pagina dichiara se ne ha (setDirty);
 * router, cambio di modalità e chiusura della scheda chiedono prima di perderle.
 */
const dirty = ref(false)
const busy = ref(false)

window.addEventListener('beforeunload', (event) => {
  if (dirty.value || busy.value) {
    event.preventDefault()
    event.returnValue = ''
  }
})

export function useDirty() {
  const ui = useUiStore()

  async function allowLeave() {
    if (busy.value) {
      ui.toast('Attendi la fine del salvataggio.')
      return false
    }
    if (!dirty.value) return true
    const ok = await ui.confirm({
      title: 'Uscire senza salvare?',
      text: 'Hai modifiche non salvate in questa pagina: uscendo andranno perse.',
      confirmLabel: 'Esci senza salvare',
    })
    if (ok) dirty.value = false
    return ok
  }

  return {
    dirty,
    busy,
    setDirty: (value) => (dirty.value = value),
    setBusy: (value) => (busy.value = value),
    allowLeave,
  }
}

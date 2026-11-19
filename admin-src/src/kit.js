/**
 * Il kit dell'editor per i moduli dei plugin (import map "@kris/editor" in index.php,
 * docs/2.0/plugin.md, 6). È l'unica interfaccia garantita alle schermate dei plugin
 * dentro la stessa api: componenti del design system, stato dell'editor, chiamate
 * alle azioni del plugin. Gli stili sono quelli dell'editor (classi e variabili di
 * styles.css): un plugin non ridefinisce l'aspetto dell'editor.
 */
import api from '@/plugins/api'

export { default as KIcon } from '@/components/KIcon.vue'
export { default as KSwitch } from '@/components/KSwitch.vue'
export { default as SortableList } from '@/components/SortableList.vue'
export { default as MediaPicker } from '@/components/MediaPicker.vue'
export { default as RichText } from '@/components/RichText.vue'
export { default as FieldInput } from '@/views/pages/edit/components/FieldInput.vue'
export { useDirty } from '@/components/dirty'
export { useSessionStore } from '@/stores/session'
export { useSiteStore } from '@/stores/site'
export { useUiStore } from '@/stores/ui'
export { ApiError, upload } from '@/plugins/api'

/** Le azioni del plugin sul server (editor.actions nel manifest, aggancio editor:<azione>). */
export function pluginApi(name) {
  return {
    get: (action, query = {}) => api.get('plugin-call', { name, action, ...query }).then((r) => r.data),
    post: (action, body = {}) => api.post('plugin-call', body, { name, action }).then((r) => r.data),
  }
}

/** Versione di questo kit: cambia solo con una api dei plugin nuova. */
export const KIT_API = 1

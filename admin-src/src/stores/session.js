import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { MODES, ROLES } from '@/config'
import SessionService from '@/services/SessionService'

/**
 * Chi è collegato e in che modalità salva. Caricata una volta all'avvio e dopo
 * ogni cambio di modalità o pubblicazione: le pagine la leggono, non la ricaricano.
 */
export const useSessionStore = defineStore('session', () => {
  const data = ref(null)

  const user = computed(() => data.value?.user ?? null)
  const isAdmin = computed(() => user.value?.role === ROLES.ADMIN)
  const mode = computed(() => data.value?.mode ?? MODES.LIVE)
  const isStaging = computed(() => mode.value === MODES.STAGING)
  const languages = computed(() => data.value?.languages ?? ['it'])
  const locked = computed(() => !!data.value?.lock)
  const stagingCount = computed(() => data.value?.staging?.count ?? 0)

  async function load() {
    data.value = await SessionService.get()
  }

  async function setMode(next) {
    await SessionService.setMode(next)
    await load()
  }

  function setStaging(summary) {
    if (data.value && summary) data.value.staging = summary
  }

  const languageName = (code) => data.value?.language_names?.[code] ?? code.toUpperCase()

  return { data, user, isAdmin, mode, isStaging, languages, locked, stagingCount, load, setMode, setStaging, languageName }
})

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import AccountService from '@/services/AccountService'
import VersionService from '@/services/VersionService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

/** Avvisi in cima a ogni schermata: blocchi, aggiornamenti, lavori in corso, email di recupero mancante. */
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()
const route = useRoute()

const lock = computed(() => session.data?.lock)
const migrating = ref(false)
async function migrate() {
  migrating.value = true
  try {
    await VersionService.migrate()
    window.location.reload()
  } catch (e) {
    ui.toast(e.message)
    migrating.value = false
  }
}

// Aggiornamento disponibile: il canale si legge al massimo una volta al giorno, dopo il caricamento
const latest = ref('')
const later = () => {
  try {
    return localStorage.getItem('kris-update-later')
  } catch {
    return null
  }
}
const showUpdate = computed(() => latest.value && latest.value !== later() && route.name !== 'settings')
onMounted(() => {
  const hint = session.data?.update
  if (!hint) return
  latest.value = hint.latest || ''
  if (hint.check) {
    setTimeout(async () => {
      try {
        const r = await VersionService.op('check')
        if (r.ok && r.target) latest.value = r.target
      } catch {
        // Il canale non risponde: nessun avviso
      }
    }, 1500)
  }
})
function dismissUpdate() {
  try {
    localStorage.setItem('kris-update-later', latest.value)
  } catch {
    // Senza localStorage l'avviso torna alla prossima apertura
  }
  latest.value = ''
}

const wipOn = computed(() => site.content.find((i) => i.type === 'kris_wip')?.values?.enabled === true && route.params.type !== 'kris_wip')

const email = ref('')
const password = ref('')
async function saveEmail() {
  try {
    await AccountService.setEmail(email.value, password.value)
    await session.load()
    ui.toast('Email salvata: la userai per recuperare la password.')
  } catch (e) {
    ui.toast(e.message)
  }
  password.value = ''
}
async function emailLater() {
  await AccountService.later().catch(() => {})
  session.data.email_missing = false
}
</script>

<template>
  <div v-if="lock" class="alert alert-error" role="alert">
    <KIcon name="warning" />
    <p><strong>Modifiche sospese.</strong> {{ lock.message }}</p>
    <div v-if="lock.migrations?.length" class="alert-actions">
      <button class="btn btn-primary btn-small" :disabled="migrating" @click="migrate">{{ migrating ? 'Aggiorno…' : 'Aggiorna il formato dei contenuti' }}</button>
      <small>Prima viene salvata una copia dei contenuti.</small>
    </div>
    <router-link v-else-if="lock.stale" class="btn btn-white btn-small" :to="{ name: 'settings', params: { tab: 'versione' } }">Vai alla versione di Kris</router-link>
  </div>
  <div v-else-if="session.data?.notice" class="alert" role="status"><p>{{ session.data.notice }}</p></div>
  <div v-if="showUpdate" class="alert alert-info" role="status">
    <p><strong>È disponibile Kris {{ latest }}.</strong> Prima di installarla puoi leggere le novità.</p>
    <router-link class="btn btn-primary btn-small" :to="{ name: 'settings', params: { tab: 'versione' } }">Aggiorna ora</router-link>
    <button type="button" class="text-link" @click="dismissUpdate">Più tardi</button>
  </div>
  <div v-if="wipOn" class="alert alert-warning" role="status">
    <p><strong>Lavori in corso attivi.</strong> I visitatori vedono solo la pagina di lavorazione; tu vedi il sito perché hai l'editor aperto.</p>
    <router-link class="btn btn-white btn-small" :to="{ name: 'edit', params: { type: 'kris_wip', id: 0 } }">Lavori in corso</router-link>
  </div>
  <div v-if="session.data?.email_missing && !lock" class="alert account-alert" role="status">
    <p><strong>Aggiungi la tua email.</strong> Se dimentichi la password, ti manderemo lì un link per sceglierne una nuova.</p>
    <form class="inline-form" @submit.prevent="saveEmail">
      <label class="sr-only" for="quick-email">Email</label>
      <input id="quick-email" v-model="email" class="input" type="email" autocomplete="email" placeholder="nome@esempio.it" required />
      <label class="sr-only" for="quick-password">Password attuale</label>
      <input id="quick-password" v-model="password" class="input" type="password" autocomplete="current-password" placeholder="Password attuale" required />
      <button class="btn btn-primary btn-small">Salva email</button>
      <button type="button" class="text-link" @click="emailLater">Più tardi</button>
    </form>
  </div>
</template>

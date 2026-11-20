<script setup>
import { ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import AccountService from '@/services/AccountService'
import { setCsrf } from '@/plugins/api'
import { useSessionStore } from '@/stores/session'
import { useUiStore } from '@/stores/ui'

/** L'utente collegato: email per il recupero della password e password. */
const session = useSessionStore()
const ui = useUiStore()

const email = ref(session.user?.email || '')
const emailPassword = ref('')
const password = ref({ current: '', next: '', again: '' })
const busy = ref(false)

async function run(fn, done) {
  busy.value = true
  try {
    const r = await fn()
    ui.toast(r?.message || done)
    return r
  } catch (e) {
    ui.toast(e.message)
  } finally {
    busy.value = false
  }
}
async function saveEmail() {
  await run(() => AccountService.setEmail(email.value, emailPassword.value), 'Email salvata: la userai per recuperare la password.')
  emailPassword.value = ''
  await session.load()
}
async function savePassword() {
  const r = await run(() => AccountService.setPassword(password.value.next, password.value.again, password.value.current), 'Password aggiornata. Le altre sessioni aperte con il tuo utente sono state chiuse.')
  if (r?.csrf) setCsrf(r.csrf)
  password.value = { current: '', next: '', again: '' }
}
</script>

<template>
  <section class="card">
    <header class="card-header">
      <span class="collection-icon"><KIcon name="user" /></span>
      <div><h2>Account</h2><p>Accedi come <strong>{{ session.user?.name }}</strong>{{ session.isAdmin ? ', amministratore' : '' }}.</p></div>
    </header>
    <div class="card-body account-body">
      <form class="account-form" @submit.prevent="saveEmail">
        <label class="field-label">Email per recuperare la password<input v-model="email" class="input" type="email" autocomplete="email" placeholder="nome@esempio.it" required /></label>
        <label class="field-label">Password attuale, per confermare<input v-model="emailPassword" class="input" type="password" autocomplete="current-password" required /></label>
        <button class="btn btn-white" :disabled="busy">Salva email</button>
      </form>
      <form v-if="session.user?.email" class="account-form" @submit.prevent="run(() => AccountService.testEmail())">
        <p class="hint">Inviati un'email di prova: se non arriva, su questo hosting il recupero della password via email non funziona.</p>
        <button class="btn btn-white" :disabled="busy">Invia email di prova</button>
      </form>
      <form class="account-form" @submit.prevent="savePassword">
        <p class="account-title">Cambia password</p>
        <label class="field-label">Password attuale<input v-model="password.current" class="input" type="password" autocomplete="current-password" required /></label>
        <label class="field-label">Nuova password (almeno 10 caratteri)<input v-model="password.next" class="input" type="password" autocomplete="new-password" minlength="10" required /></label>
        <label class="field-label">Ripeti la nuova password<input v-model="password.again" class="input" type="password" autocomplete="new-password" minlength="10" required /></label>
        <button class="btn btn-white" :disabled="busy">Aggiorna password</button>
      </form>
      <p class="hint">Se perdi l'accesso e l'email non arriva, chiedi a un amministratore del sito di reimpostarti la password.</p>
    </div>
  </section>
</template>

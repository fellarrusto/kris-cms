<script setup>
import { onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import AgentService from '@/services/AgentService'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/views/pages/media/format'

/** Kris Agent: abbinamento del sito e chi può usare l'assistente. */
const ui = useUiStore()
const data = ref(null)
const code = ref('')
const busy = ref(false)

const REASONS = {
  ok: 'Può usare l’assistente',
  platform_admin: 'Amministratore di Kris Agent: può sempre',
  grant_pending: 'Aspetta la tua approvazione',
  grant_revoked: 'Escluso da te',
  no_subscription: 'Senza abbonamento',
  subscription_suspended: 'Abbonamento sospeso',
  subscription_not_started: 'Abbonamento non ancora iniziato',
  subscription_expired: 'Abbonamento scaduto',
  site_not_active: 'Sito non attivo in Kris Agent',
  user_blocked: 'Utente bloccato',
}

async function load() {
  try {
    data.value = await AgentService.get()
  } catch (e) {
    ui.toast(e.message)
  }
}
onMounted(load)

async function run(fn, done, confirmText) {
  if (confirmText && !(await ui.confirm(confirmText))) return
  busy.value = true
  try {
    await fn()
    ui.toast(done)
    code.value = ''
    await load()
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}
</script>

<template>
  <section class="card">
    <header class="card-header">
      <span class="collection-icon"><KIcon name="agent" /></span>
      <div><h2>Kris Agent</h2><p>Permette a un assistente AI di preparare modifiche al sito. Le modifiche restano in attesa finché non le pubblica qualcuno da «Da pubblicare».</p></div>
    </header>
    <div class="card-body">
      <p v-if="!data" class="muted">Carico…</p>
      <form v-else-if="!data.paired" class="agent-pair-form" @submit.prevent="run(() => AgentService.pair(code), 'Sito abbinato a Kris Agent.')">
        <label class="field-label">Codice di abbinamento<textarea v-model="code" class="input mono" rows="3" required placeholder="KRIS1-…" spellcheck="false"></textarea></label>
        <small class="muted">Lo trovi nel pannello di Kris Agent, nella scheda di questo sito. Vale 30 minuti e funziona solo per <strong>{{ data.site_url }}</strong>.</small>
        <button class="btn btn-primary" :disabled="busy">Abbina il sito</button>
      </form>
      <template v-else>
        <div class="setting-row static"><span><strong>Stato</strong><small>{{ data.enabled ? 'L’assistente può preparare modifiche.' : 'Spento: le richieste dell’assistente vengono rifiutate.' }}</small></span><span class="setting-value">{{ data.enabled ? 'Attivo' : 'Spento' }}</span></div>
        <div class="setting-row static"><span><strong>Abbinato</strong><small>{{ data.worker_url }}</small></span><span class="setting-value">{{ formatDate(data.paired_at) }}</span></div>
        <div class="agent-actions">
          <button type="button" class="btn btn-white" :disabled="busy" @click="run(() => AgentService.toggle(!data.enabled), data.enabled ? 'Kris Agent spento.' : 'Kris Agent riacceso.')">{{ data.enabled ? 'Spegni Kris Agent' : 'Riaccendi Kris Agent' }}</button>
          <button
            type="button"
            class="btn btn-danger"
            :disabled="busy"
            @click="run(() => AgentService.unpair(), 'Sito scollegato da Kris Agent.', { title: 'Scollegare il sito?', text: 'Kris Agent non potrà più preparare modifiche. Per ricollegarlo servirà un nuovo codice dal pannello. Le modifiche già in attesa restano.', confirmLabel: 'Scollega' })"
          >
            Scollega
          </button>
        </div>
        <div class="agent-users">
          <h3>Chi può usare l'assistente su questo sito</h3>
          <p class="muted">Decidi tu chi entra. Un collaboratore compare qui quando riceve un abbonamento per questo sito.</p>
          <div v-if="data.users_error" class="alert alert-error" role="alert"><p>{{ data.users_error }}</p></div>
          <p v-else-if="!data.users?.length" class="agent-empty">Ancora nessuno.</p>
          <ul v-else class="agent-user-list">
            <li v-for="u in data.users" :key="u.user_id" class="agent-user">
              <span style="flex: 1"><strong>{{ u.name }}</strong><br /><small class="muted">{{ u.email }}</small></span>
              <span class="agent-user-state" :class="{ 'is-allowed': u.allowed }">{{ REASONS[u.reason] || u.reason }}</span>
              <template v-if="u.reason !== 'platform_admin'">
                <button v-if="u.grant_status === 'approved'" type="button" class="btn btn-white btn-small" :disabled="busy" @click="run(() => AgentService.grant(u.user_id, 'revoked'), 'Collaboratore escluso.')">Escludi</button>
                <button v-else type="button" class="btn btn-primary btn-small" :disabled="busy" @click="run(() => AgentService.grant(u.user_id, 'approved'), 'Collaboratore approvato.')">Approva</button>
              </template>
            </li>
          </ul>
          <div class="agent-reset">
            <button
              type="button"
              class="btn btn-danger btn-small"
              :disabled="busy"
              @click="run(() => AgentService.reset(), 'Accesso reimpostato: chiavi nuove, e ogni collaboratore aspetta di nuovo l’approvazione.', { title: 'Reimpostare l’accesso?', text: 'Tutti dovranno essere approvati di nuovo e le chiavi del collegamento cambiano. Usalo quando un collaboratore smette di lavorare con te.', confirmLabel: 'Reimposta' })"
            >
              Reimposta l'accesso
            </button>
            <small class="muted">Tutti tornano in attesa di approvazione.</small>
          </div>
        </div>
      </template>
    </div>
  </section>
</template>

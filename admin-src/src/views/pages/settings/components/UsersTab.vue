<script setup>
import { onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import UserService from '@/services/UserService'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/views/pages/media/format'

/**
 * Gli utenti dell'editor, solo per gli amministratori: l'admin crea un utente con la
 * sua password, lo blocca temporaneamente o lo elimina. Ognuno cambia da sé email e password.
 */
const ui = useUiStore()

const users = ref([])
const me = ref('')
const loading = ref(true)
const busy = ref(false)
const adding = ref(false)
const draft = ref({ name: '', email: '', password: '', role: 'base' })
const newPassword = ref({}) // nome → password in corso di impostazione

async function load() {
  try {
    const r = await UserService.list()
    users.value = r.users
    me.value = r.me
  } catch (e) {
    ui.toast(e.message)
  }
  loading.value = false
}
onMounted(load)

async function run(fn, done) {
  busy.value = true
  try {
    const r = await fn()
    users.value = r.users || users.value
    ui.toast(done)
    return true
  } catch (e) {
    ui.toast(e.message)
    return false
  } finally {
    busy.value = false
  }
}

async function create() {
  if (await run(() => UserService.create(draft.value), `Utente «${draft.value.name}» creato. Comunicagli nome utente e password: l'email gli serve per recuperarla.`)) {
    draft.value = { name: '', email: '', password: '', role: 'base' }
    adding.value = false
  }
}
async function toggleBlock(u) {
  if (u.blocked) return run(() => UserService.unblock(u.name), `${u.name} può di nuovo accedere.`)
  if (await ui.confirm({ title: `Bloccare ${u.name}?`, text: 'Non potrà accedere finché non lo sblocchi; se è collegato, la sua sessione si chiude subito. I suoi dati restano.', confirmLabel: 'Blocca' })) {
    run(() => UserService.block(u.name), `${u.name} è bloccato.`)
  }
}
async function remove(u) {
  if (await ui.confirm({ title: `Eliminare ${u.name}?`, text: 'Non potrà più accedere e la sua sessione si chiude subito. Le modifiche che ha già fatto restano sul sito.', confirmLabel: 'Elimina utente' })) {
    run(() => UserService.remove(u.name), `${u.name} è stato eliminato.`)
  }
}
const setRole = (u, role) => run(() => UserService.setRole(u.name, role), role === 'admin' ? `${u.name} ora è amministratore.` : `${u.name} ora è un utente base.`)
async function setPassword(u) {
  if (await run(() => UserService.setPassword(u.name, newPassword.value[u.name] || ''), `Password di ${u.name} cambiata: le sue sessioni aperte si sono chiuse.`)) delete newPassword.value[u.name]
}
</script>

<template>
  <section class="card">
    <header class="card-header">
      <span class="collection-icon"><KIcon name="user" /></span>
      <div><h2>Utenti</h2><p>Chi può accedere all'editor. Gli amministratori, in più, gestiscono gli utenti; per il resto tutti possono fare tutto.</p></div>
    </header>
    <div class="card-body">
      <p v-if="loading" class="muted">Carico gli utenti…</p>
      <div v-else style="overflow-x: auto">
        <table class="table-list">
          <thead><tr><th scope="col">Utente</th><th scope="col">Ruolo</th><th scope="col">Stato</th><th scope="col"><span class="sr-only">Azioni</span></th></tr></thead>
          <tbody>
            <tr v-for="u in users" :key="u.name">
              <td>
                <strong style="font-weight: 500">{{ u.name }}</strong><span v-if="u.name === me" class="pill" style="margin-left: 6px">Tu</span>
                <div class="muted">{{ u.email || 'Nessuna email: non può recuperare la password da solo' }}<template v-if="u.created_at"> · dal {{ formatDate(u.created_at) }}</template></div>
              </td>
              <td>
                <select class="input" style="width: auto" :value="u.role" :disabled="busy || u.name === me" :aria-label="`Ruolo di ${u.name}`" @change="setRole(u, $event.target.value)">
                  <option value="base">Utente</option>
                  <option value="admin">Amministratore</option>
                </select>
              </td>
              <td><span class="pill" :class="u.blocked ? 'pill-danger' : 'pill-ok'">{{ u.blocked ? 'Bloccato' : 'Attivo' }}</span></td>
              <td>
                <div v-if="u.name !== me" class="row-actions">
                  <button type="button" class="btn btn-white btn-small" :disabled="busy" @click="toggleBlock(u)">{{ u.blocked ? 'Sblocca' : 'Blocca' }}</button>
                  <button type="button" class="icon-button danger" :aria-label="`Elimina ${u.name}`" :disabled="busy" @click="remove(u)"><KIcon name="trash" /></button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <details v-if="users.length > 1" class="update-manual">
        <summary>Imposta una nuova password a un utente</summary>
        <p class="hint">Per chi ha perso l'accesso e non riceve l'email di recupero. Le sue sessioni aperte si chiudono.</p>
        <form v-for="u in users.filter((x) => x.name !== me)" :key="u.name" class="inline-form" style="margin-top: 8px" @submit.prevent="setPassword(u)">
          <label class="sr-only" :for="`pw-${u.name}`">Nuova password di {{ u.name }}</label>
          <input :id="`pw-${u.name}`" v-model="newPassword[u.name]" class="input" type="password" autocomplete="new-password" minlength="10" :placeholder="`Nuova password di ${u.name}`" required />
          <button class="btn btn-white btn-small" :disabled="busy">Imposta</button>
        </form>
      </details>
      <button v-if="!adding" type="button" class="btn btn-primary btn-small" style="align-self: flex-start" @click="adding = true"><KIcon name="plus" />Nuovo utente</button>
      <form v-else class="box collection-info-form" style="background: var(--surface-2)" @submit.prevent="create">
        <h2>Nuovo utente</h2>
        <div class="form-grid">
          <label class="field-label">Nome utente<input v-model.trim="draft.name" class="input" type="text" autocomplete="off" pattern="[a-z0-9][a-z0-9._\-]{1,39}" placeholder="mario.rossi" required /></label>
          <label class="field-label">Email per il recupero della password<input v-model.trim="draft.email" class="input" type="email" autocomplete="off" placeholder="nome@esempio.it" /></label>
          <label class="field-label">Password (almeno 10 caratteri)<input v-model="draft.password" class="input" type="password" autocomplete="new-password" minlength="10" required /></label>
          <label class="field-label">Ruolo<select v-model="draft.role" class="input"><option value="base">Utente</option><option value="admin">Amministratore</option></select></label>
        </div>
        <p class="hint">Nome utente in minuscolo: lettere, numeri, punto, trattino e _. Comunica tu nome e password; l'utente potrà cambiarsela.</p>
        <div class="bar"><button type="button" class="btn btn-white btn-small" @click="adding = false">Annulla</button><button class="btn btn-primary btn-small" :disabled="busy">Crea utente</button></div>
      </form>
    </div>
  </section>
</template>

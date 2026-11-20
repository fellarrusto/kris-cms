<script setup>
import { computed, onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import StagingService from '@/services/StagingService'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { formatDate } from '@/views/pages/media/format'

/** Le modifiche in attesa: riepilogo, avvisi, codice da approvare, pubblicazione. */
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const data = ref(null)
const busy = ref(false)
const approved = ref(false)
const openFiles = ref(new Set())

async function load() {
  try {
    data.value = await StagingService.get()
    session.setStaging({ count: data.value.count, visible: data.value.visible })
  } catch (e) {
    ui.toast(e.message)
  }
}
onMounted(load)

const total = computed(() => data.value?.count ?? 0)
const conflicts = computed(() => (data.value?.operations || []).filter((o) => o.conflict))
const fileConflicts = computed(() => (data.value?.files || []).filter((f) => f.conflict))
const blocked = computed(() => conflicts.value.length || fileConflicts.value.length || (data.value?.problems || []).length)
const codePending = computed(() => data.value?.code?.files?.length && !data.value.code.approved)
const checks = computed(() => conflicts.value.length + fileConflicts.value.length + (data.value?.problems || []).length + (codePending.value ? 1 : 0))
const KIND = { new: 'Nuovo', modified: 'Modificato', deleted: 'Da eliminare' }

async function act(fn, confirmText, done) {
  if (confirmText && !(await ui.confirm(confirmText))) return
  busy.value = true
  try {
    const r = await fn()
    if (done) ui.toast(typeof done === 'function' ? done(r) : done)
    await session.load()
    await site.load()
    await load()
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}

const promote = () =>
  act(
    () => StagingService.promote(!!blocked.value),
    {
      title: 'Pubblicare le modifiche?',
      text: `${blocked.value ? 'Ci sono avvisi: le modifiche non applicabili verranno saltate. ' : ''}Le versioni attuali vengono salvate: potrai annullare la pubblicazione per ${data.value.backup_days} giorni.`,
      confirmLabel: 'Pubblica',
      danger: false,
    },
    'Modifiche pubblicate.'
  )
const discard = () => act(() => StagingService.discard(), { title: 'Scartare le modifiche?', text: 'Le modifiche in attesa verranno eliminate. Il sito non cambia.', confirmLabel: 'Scarta' }, 'Modifiche scartate.')
const undo = () =>
  act(
    () => StagingService.undo(),
    { title: 'Annullare la pubblicazione?', text: 'I file pubblicati, compresi contenuti e struttura, tornano alla versione precedente. Le modifiche fatte dopo su quei file andranno perse.', confirmLabel: 'Annulla pubblicazione' },
    (r) => `Pubblicazione annullata: ${r.restored} file sono tornati com'erano.`
  )
const approve = () => act(() => StagingService.approve(data.value.code.fingerprint), null, 'Codice approvato: l\'anteprima ora lo esegue.')
function toggleFile(name) {
  const set = new Set(openFiles.value)
  set.has(name) ? set.delete(name) : set.add(name)
  openFiles.value = set
}
</script>

<template>
  <div class="container">
    <p v-if="!data" class="loading">Carico le modifiche in attesa…</p>
    <template v-else>
      <div class="page-head">
        <div>
          <h1>{{ total ? `${total} ${total === 1 ? 'modifica da rivedere' : 'modifiche da rivedere'}` : 'Niente da pubblicare' }}</h1>
          <p class="sub">{{ total ? 'Il sito pubblico non cambia finché non le pubblichi.' : 'Non ci sono modifiche in attesa.' }}</p>
        </div>
        <a v-if="total" class="btn btn-white" :href="`${session.data.site_url}index.php?kris_preview=1`" target="_blank" rel="noopener">Apri l'anteprima <KIcon name="external" /></a>
      </div>
      <div class="publish-cols">
        <div class="container">
          <template v-if="total">
            <div class="box">
              <h2>Riepilogo</h2>
              <p class="sub" style="font-size: 14px">Preparate da {{ data.created_by || '—' }}<template v-if="data.created_at">, dal {{ formatDate(data.created_at) }}</template>.</p>
              <div class="stats">
                <div><span>Contenuti e struttura</span><b>{{ data.operations.length }}</b></div>
                <div><span>File caricati</span><b>{{ data.uploads.length + data.fonts.length }}</b></div>
                <div><span>File del tema</span><b>{{ data.files.length }}</b></div>
                <div><span>Da controllare</span><b :style="checks ? 'color: var(--warn-line)' : ''">{{ checks }}</b></div>
              </div>
            </div>
            <div v-if="blocked" class="notice notice-warning" role="alert">
              <strong>Prima di pubblicare, controlla</strong>
              <ul class="staging-problems">
                <li v-for="f in fileConflicts" :key="f.file">{{ f.file }} è stato cambiato anche sul sito: pubblicando lo sostituisci.</li>
                <li v-for="(o, i) in conflicts" :key="'c' + i">{{ o.conflict }} Pubblicando, verrà saltata.</li>
                <li v-for="p in data.problems" :key="p">{{ p }}: dopo la pubblicazione lì comparirà un vuoto.</li>
              </ul>
            </div>
            <div v-if="data.code.files.length" class="box code-approval">
              <template v-if="data.code.approved">
                <h2>Codice approvato</h2>
                <p class="sub" style="font-size: 14px">L'anteprima esegue il codice nuovo di {{ data.code.files.join(', ') }}. Se cambia, l'approvazione va rinnovata.</p>
              </template>
              <template v-else>
                <h2>Codice da approvare</h2>
                <p class="sub" style="font-size: 14px; line-height: 1.55">
                  Queste modifiche aggiungono codice eseguito dal browser: {{ data.code.files.join(', ') }}. Gira con il tuo accesso all'editor aperto e potrebbe agire a nome tuo: finché non lo
                  approvi, l'anteprima non lo esegue. Controllalo qui sotto, fra i file del tema.
                </p>
                <label class="check-row"><input v-model="approved" type="checkbox" /><span>Ho controllato il codice e me ne assumo la responsabilità.</span></label>
                <button type="button" class="btn btn-primary btn-small" :disabled="!approved || busy" @click="approve">Approva il codice</button>
              </template>
            </div>
            <div v-if="data.warnings.length" class="box">
              <h2>Da sapere</h2>
              <ul class="staging-problems"><li v-for="w in data.warnings" :key="w">{{ w }}</li></ul>
            </div>
            <div v-if="data.preview_pages.length" class="box">
              <h2>Pagine da guardare</h2>
              <p class="sub" style="font-size: 14px">Le pagine che cambiano, ognuna con la sua anteprima: anche quelle che nessun link raggiunge.</p>
              <ul class="staging-problems"><li v-for="p in data.preview_pages" :key="p.url"><a class="text-link" :href="p.url" target="_blank" rel="noopener">{{ p.label }} <KIcon name="external" /></a></li></ul>
            </div>
            <div v-if="data.operations.length" class="box">
              <h2>Contenuti, struttura e pagine</h2>
              <p class="sub" style="font-size: 14px">Applicate ai contenuti del sito al momento della pubblicazione: le modifiche fatte intanto sul sito restano.</p>
              <ol class="staging-ops">
                <li v-for="(o, i) in data.operations" :key="i" :class="{ 'has-conflict': o.conflict }">
                  <span>{{ o.description }} <span v-if="o.conflict" class="pill pill-warn">Non applicabile</span></span>
                  <span v-if="o.value" class="staging-value">{{ o.value }}</span>
                  <small>{{ o.by }} · {{ formatDate(o.at) }}</small>
                </li>
              </ol>
            </div>
            <div v-if="data.uploads.length" class="box">
              <h2>File caricati</h2>
              <p class="sub" style="font-size: 14px">Entrano nella libreria media con la pubblicazione.</p>
              <div class="card-body staging-uploads" style="margin-top: 12px">
                <figure v-for="u in data.uploads" :key="u.name"><img v-if="/\.(jpe?g|png|gif|webp|avif|svg)$/i.test(u.name)" :src="u.src" alt="" /><span v-else class="file-placeholder">{{ u.name.split('.').pop().toUpperCase() }}</span><figcaption>{{ u.original }}</figcaption></figure>
              </div>
            </div>
            <div v-if="data.fonts.length" class="box">
              <h2>Font del tema</h2>
              <ul class="staging-problems"><li v-for="f in data.fonts" :key="f.name"><code>assets/fonts/{{ f.name }}</code> · {{ f.by }}</li></ul>
            </div>
            <div v-if="data.files.length" class="box">
              <h2>File del tema</h2>
              <div class="card-body staging-files" style="margin-top: 12px">
                <article v-for="f in data.files" :key="f.file" class="staging-file">
                  <header class="staging-file-head">
                    <code>{{ f.file }}</code><span class="pill" :class="{ 'pill-danger': f.kind === 'deleted' }">{{ KIND[f.kind] }}</span>
                    <span v-if="f.conflict" class="pill pill-warn">Cambiato anche sul sito</span><small>{{ f.by }} · {{ formatDate(f.at) }}</small>
                    <button type="button" class="text-link" :aria-expanded="String(openFiles.has(f.file))" @click="toggleFile(f.file)">{{ openFiles.has(f.file) ? 'Nascondi le differenze' : 'Mostra le differenze' }}</button>
                  </header>
                  <div v-if="f.sensitive.length" class="notice notice-warning"><strong>Da controllare prima di pubblicare</strong><p>Questa modifica aggiunge: {{ f.sensitive.join('; ') }}.</p></div>
                  <pre v-if="openFiles.has(f.file)" class="diff-view"><span v-for="(line, i) in f.diff" :key="i" :class="`diff-${{ '+': 'add', '-': 'del', ' ': 'same', '…': 'gap' }[line[0]]}`">{{ line[0] === '…' ? '⋯' : `${line[0]} ${line[1]}` }}</span></pre>
                </article>
              </div>
            </div>
          </template>
          <div v-else class="empty-state"><h2>Tutto pubblicato.</h2><p>Passa a «In attesa» per preparare modifiche da rivedere prima di pubblicarle.</p></div>
        </div>
        <aside class="publish-side">
          <div v-if="total" class="publish-box">
            <button type="button" class="btn btn-pending" :disabled="busy || session.locked" @click="promote">{{ blocked ? 'Pubblica comunque' : 'Pubblica sul sito' }}</button>
            <p>
              <template v-if="conflicts.length">{{ conflicts.length }} {{ conflicts.length === 1 ? 'modifica non applicabile verrà saltata.' : 'modifiche non applicabili verranno saltate.' }} </template>
              <template v-if="codePending">Il codice non approvato non gira nell'anteprima, ma si pubblica. </template>
              Potrai annullare la pubblicazione per {{ data.backup_days }} giorni.
            </p>
            <button type="button" class="btn" :disabled="busy" @click="discard">Scarta tutte le modifiche</button>
          </div>
          <div v-if="data.last_promotion" class="box">
            <h2>Ultima pubblicazione</h2>
            <p class="muted" style="margin: 6px 0 12px">{{ formatDate(data.last_promotion.at) }} · {{ data.last_promotion.by }}<br />{{ data.last_promotion.files.join(', ') }}</p>
            <button type="button" class="btn btn-white btn-small" :disabled="busy" @click="undo">Annulla la pubblicazione</button>
          </div>
        </aside>
      </div>
    </template>
  </div>
</template>

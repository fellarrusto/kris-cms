<script setup>
import { computed, ref, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import KIcon from '@/components/KIcon.vue'
import PageHead from '@/components/PageHead.vue'
import KSwitch from '@/components/KSwitch.vue'
import SortableList from '@/components/SortableList.vue'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'
import { isNewestFirst, isSingle, mediaSrc, moves, subtitle, thumb, title, typeDescription } from '@/lib/model'

const route = useRoute()
const router = useRouter()
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const type = computed(() => String(route.params.type))
const fields = computed(() => site.fieldsOf(type.value))
const lang = computed(() => session.languages[0])
const search = ref('')
const saving = ref(false)

const rows = computed(() =>
  site.items(type.value).map((i) => {
    const heading = title(i, fields.value, lang.value)
    return {
      id: Number(i.id),
      hidden: i.hidden === true,
      title: heading,
      sub: subtitle(i, fields.value, lang.value, heading),
      thumb: thumb(i, fields.value),
    }
  })
)
const visible = computed(() =>
  search.value ? rows.value.filter((r) => `${r.title} ${r.sub}`.toLowerCase().includes(search.value.toLowerCase())) : rows.value
)
const hiddenCount = computed(() => rows.value.filter((r) => r.hidden).length)
const single = computed(() => isSingle(site.schema, type.value))
// Con un solo contenuto sospenderlo spegnerebbe la pagina
const canHide = computed(() => rows.value.length > 1)
const stagedNames = computed(() => new Set(session.data?.staged_uploads || []))

// Una raccolta che non c'è più, o un contenuto unico: altrove
watchEffect(() => {
  if (!site.type(type.value)) router.replace({ name: 'dashboard' })
  else if (single.value && rows.value.length) router.replace({ name: 'edit', params: { type: type.value, id: rows.value[0].id } })
})

async function run(ops, done) {
  if (saving.value) return
  saving.value = true
  try {
    const result = await site.apply(ops)
    if (done) ui.toast(done)
    return result
  } catch (e) {
    ui.toast(e.message)
    await site.load()
  } finally {
    saving.value = false
  }
}

async function create() {
  const result = await run([{ op: 'create_entity', key: type.value }])
  if (!result) return
  const id = result.done[0].assigned_id
  // In una raccolta di articoli il più recente va in cima
  if (isNewestFirst(site.schema, type.value)) await run([{ op: 'move_entity', key: type.value, id, to: 0 }])
  router.push({ name: 'edit', params: { type: type.value, id } })
}

function reorder(ids) {
  const ops = moves(
    rows.value.map((r) => r.id),
    ids
  ).map(([id, to]) => ({ op: 'move_entity', key: type.value, id, to }))
  if (ops.length) run(ops, session.isStaging ? 'Ordine messo in attesa.' : 'Ordine salvato.')
}

function setVisible(row, visible) {
  run(
    [{ op: 'set_hidden', key: type.value, id: row.id, hidden: !visible }],
    visible ? 'Visibile sul sito.' : 'Sospeso: il sito non lo mostra.'
  )
}

async function remove(row) {
  const ok = await ui.confirm({
    title: 'Eliminare questo contenuto?',
    text: `«${row.title}» e i suoi elementi verranno eliminati${session.isStaging ? ' quando pubblichi' : ' subito dal sito'}.`,
    confirmLabel: 'Elimina',
  })
  if (ok) run([{ op: 'remove_entity', key: type.value, id: row.id }], 'Contenuto eliminato.')
}
</script>

<template>
  <div class="container">
    <PageHead>
      <div>
        <h1>{{ site.label(type) }}</h1>
        <p class="sub">
          <template v-if="typeDescription(site.schema, type)">{{ `${typeDescription(site.schema, type)} ` }}</template>
          <span class="num">{{ rows.length }}</span> {{ rows.length === 1 ? 'contenuto' : 'contenuti'
          }}<template v-if="hiddenCount">, {{ hiddenCount }} {{ hiddenCount === 1 ? 'sospeso' : 'sospesi' }}</template
          >.
          <template v-if="isNewestFirst(site.schema, type)"> I nuovi entrano in cima.</template>
        </p>
      </div>
      <template #actions>
        <div class="actions">
          <router-link v-if="!session.isStaging" class="btn btn-white" :to="{ name: 'structure-type', params: { type } }"
            ><KIcon name="code" />Struttura</router-link
          >
          <button
            v-if="fields.length && !(single && rows.length)"
            class="btn btn-primary"
            :disabled="saving || session.locked"
            @click="create"
          >
            <KIcon name="plus" />Nuovo contenuto
          </button>
        </div>
      </template>
    </PageHead>

    <div v-if="!fields.length" class="empty-state">
      <h2>Prima definiamo cosa raccontare.</h2>
      <p>Questa raccolta non ha ancora campi: scegli in Struttura cosa contiene ogni contenuto.</p>
      <router-link v-if="!session.isStaging" class="btn btn-primary" :to="{ name: 'structure-type', params: { type } }"
        >Scegli i campi</router-link
      >
    </div>
    <div v-else-if="!rows.length" class="empty-state">
      <h2>Il primo contenuto, quando vuoi.</h2>
      <p>Usa «Nuovo contenuto» per iniziare a compilare la raccolta.</p>
    </div>
    <template v-else>
      <div class="toolbar">
        <label class="search"
          ><KIcon name="search" /><input
            v-model="search"
            type="search"
            :placeholder="`Cerca in ${site.label(type).toLowerCase()}`"
            aria-label="Cerca un contenuto"
        /></label>
        <span class="order-note">{{
          search
            ? "Svuota la ricerca per cambiare l'ordine."
            : "Trascina dalla maniglia, o usa le frecce sulla maniglia, per cambiare l'ordine sul sito. Si salva da solo."
        }}</span>
      </div>
      <SortableList :items="visible" :disabled="!!search || saving || session.locked" @reorder="reorder">
        <template #default="{ item: row }">
          <button
            type="button"
            class="icon-button handle"
            data-handle
            :disabled="!!search"
            :aria-label="`Sposta ${row.title}: trascina, o usa le frecce su e giù`"
          >
            <KIcon name="drag" />
          </button>
          <router-link class="row-main" :to="{ name: 'edit', params: { type, id: row.id } }">
            <span v-if="row.thumb" class="thumb"><img :src="mediaSrc(row.thumb, stagedNames)" alt="" loading="lazy" /></span>
            <span class="row-text"
              ><span class="row-title">{{ row.title }}</span
              ><span v-if="row.sub" class="row-sub">{{ row.sub }}</span></span
            >
          </router-link>
          <div class="row-actions">
            <span v-if="row.hidden" class="pill">Sospeso</span>
            <router-link
              class="icon-button"
              :to="{ name: 'edit', params: { type, id: row.id } }"
              :aria-label="`Modifica ${row.title}`"
              title="Modifica"
              ><KIcon name="edit"
            /></router-link>
            <KSwitch
              v-if="canHide"
              :model-value="!row.hidden"
              :label="`Visibile sul sito: ${row.title}`"
              :disabled="saving || session.locked"
              @update:model-value="setVisible(row, $event)"
            />
            <button
              v-if="!single"
              type="button"
              class="icon-button danger"
              :aria-label="`Elimina ${row.title}`"
              :disabled="saving || session.locked"
              @click="remove(row)"
            >
              <KIcon name="trash" />
            </button>
          </div>
        </template>
      </SortableList>
      <div v-if="!visible.length" class="empty-state">
        <h2>Nessun contenuto trovato.</h2>
        <p>Prova un altro nome o svuota la ricerca.</p>
      </div>
    </template>
  </div>
</template>

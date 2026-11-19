<script setup>
import { computed, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import { FIELD_TYPES, fieldLabel } from '@/lib/model'

/**
 * Un campo nella Struttura: una riga compatta che si apre per modificarlo. Un elenco
 * contiene i campi dei suoi elementi (questo stesso componente, ricorsivo).
 */
const props = defineProps({
  field: { type: Object, required: true },
  sections: { type: Array, default: null }, // solo al primo livello: [{ key, name }]
  sectionKey: { type: String, default: '' },
  first: Boolean,
  last: Boolean,
  errors: { type: Object, default: () => ({}) },
  path: { type: String, default: '' },
})
const emit = defineEmits(['remove', 'move', 'section', 'changed'])

const open = ref(!props.field.name)
const key = computed(() => (props.path ? props.path + ' / ' : '') + (props.field.name || '…'))
const error = computed(() => props.errors[props.field._key])

function setType(type) {
  const f = props.field
  f.type = type
  if (type === 'list') {
    f.of ??= []
    delete f.localized
  } else {
    // I testi di solito cambiano con la lingua, interruttori e scelte no
    if (!f._touchedLocalized) f.localized = ['text', 'richtext', 'media'].includes(type)
    if (type === 'select') {
      f.options ??= []
      f.multiple ??= false
    }
  }
  emit('changed')
}
const optionsText = computed({
  get: () => (props.field.options || []).join('\n'),
  set: (value) => {
    props.field.options = value.split('\n').map((o) => o.trim()).filter(Boolean)
    emit('changed')
  },
})

let counter = 0
function addChild() {
  props.field.of.push({ _key: `n${Date.now().toString(36)}${counter++}`, name: '', type: 'text', localized: true, description: '' })
  emit('changed')
}
function moveChild(index, delta) {
  const list = props.field.of
  const [moved] = list.splice(index, 1)
  list.splice(index + delta, 0, moved)
  emit('changed')
}
function removeChild(index) {
  props.field.of.splice(index, 1)
  emit('changed')
}
</script>

<template>
  <div class="sf-row" :class="{ 'is-invalid': error }">
    <div class="sf-header">
      <div class="sf-order">
        <button type="button" class="icon-button" :disabled="first" :aria-label="`Sposta su ${key}`" @click="$emit('move', -1)"><KIcon name="up" /></button>
        <button type="button" class="icon-button" :disabled="last" :aria-label="`Sposta giù ${key}`" @click="$emit('move', 1)"><KIcon name="down" /></button>
      </div>
      <button type="button" class="sf-summary" :aria-expanded="String(open)" @click="open = !open">
        <span class="sf-label">{{ field.name ? fieldLabel(field) : 'Campo nuovo' }}</span>
        <span class="mono">{{ field.name || '—' }}</span>
        <span class="pill">{{ FIELD_TYPES[field.type] }}</span>
        <span v-if="field.type !== 'list'" class="muted">{{ field.localized ? 'Per lingua' : 'Condiviso' }}</span>
        <span v-else class="muted">{{ field.of.length }} {{ field.of.length === 1 ? 'campo' : 'campi' }}</span>
      </button>
      <button type="button" class="icon-button danger" :aria-label="`Togli ${key}`" @click="$emit('remove')"><KIcon name="close" /></button>
    </div>
    <p v-if="error" class="field-error" role="alert">{{ error }}</p>
    <div v-if="open" class="sf-details">
      <div class="form-grid">
        <label class="field-label">Nome tecnico<input v-model.trim="field.name" class="input mono" type="text" required pattern="[a-z][a-z0-9_]*" placeholder="nome_tecnico" :aria-invalid="String(!!error)" @input="$emit('changed')" /></label>
        <label class="field-label">Tipo
          <select class="input" :value="field.type" @change="setType($event.target.value)">
            <option v-for="(label, value) in FIELD_TYPES" :key="value" :value="value">{{ label }}</option>
          </select>
        </label>
      </div>
      <label class="field-label">Etichetta per chi modifica<input v-model="field.description" class="input" type="text" placeholder="Es. Titolo in testa alla pagina" @input="$emit('changed')" /></label>
      <label v-if="sections" class="field-label">Sezione
        <select class="input" :value="sectionKey" @change="$emit('section', $event.target.value)">
          <option v-for="s in sections" :key="s.key" :value="s.key">{{ s.key === 'none' ? 'Nessuna sezione' : s.name || 'Sezione senza nome' }}</option>
        </select>
      </label>
      <template v-if="field.type !== 'list'">
        <label class="check-row"><input v-model="field.localized" type="checkbox" @change="(field._touchedLocalized = true), $emit('changed')" /><span><strong>Diverso per lingua</strong><small>Spento: un solo valore per tutte le lingue (prezzi, codici, indirizzi).</small></span></label>
      </template>
      <template v-if="field.type === 'select'">
        <label class="field-label">Opzioni, una per riga<textarea v-model.lazy="optionsText" class="input" rows="4"></textarea></label>
        <label class="check-row"><input v-model="field.multiple" type="checkbox" @change="$emit('changed')" /><span><strong>Più opzioni insieme</strong><small>Per tag e categorie: nei template si controllano con «has».</small></span></label>
      </template>
    </div>
    <div v-if="field.type === 'list'" class="sf-nested">
      <p class="sf-nested-label">Campi di ogni elemento</p>
      <FieldRow
        v-for="(child, i) in field.of"
        :key="child._key"
        :field="child"
        :first="i === 0"
        :last="i === field.of.length - 1"
        :errors="errors"
        :path="key"
        @move="moveChild(i, $event)"
        @remove="removeChild(i)"
        @changed="$emit('changed')"
      />
      <button type="button" class="btn btn-white btn-small" @click="addChild"><KIcon name="plus" />Campo dell'elemento</button>
    </div>
  </div>
</template>

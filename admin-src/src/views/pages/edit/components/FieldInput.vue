<script setup>
import { computed, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import KSwitch from '@/components/KSwitch.vue'
import MediaPicker from '@/components/MediaPicker.vue'
import RichText from '@/components/RichText.vue'
import { mediaKind, mediaSrc } from '@/lib/model'

/** Il controllo di un campo per un valore (una lingua, o il valore condiviso). */
const props = defineProps({
  def: { type: Object, required: true },
  modelValue: { type: [String, Boolean, Array, Number, null], default: null },
  id: { type: String, required: true },
  label: { type: String, required: true },
  stagedNames: { type: Set, default: () => new Set() },
  accept: { type: String, default: '' },
  changed: Boolean,
})
const emit = defineEmits(['update:modelValue'])

const picker = ref(null) // null | { kind: 'field' } | { kind: 'richtext', callback }
const text = computed(() => (typeof props.modelValue === 'string' ? props.modelValue : ''))
const picked = computed(() => (Array.isArray(props.modelValue) ? props.modelValue.map(String) : props.modelValue ? [String(props.modelValue)] : []))

function toggle(option, on) {
  const set = new Set(picked.value)
  if (on) set.add(option)
  else set.delete(option)
  // Nell'ordine delle opzioni, come le salva il server
  emit(
    'update:modelValue',
    (props.def.options || []).filter((o) => set.has(o))
  )
}

function grow(event) {
  const el = event.target
  el.style.height = 'auto'
  // scrollHeight non conta i bordi: con box-sizing border-box vanno aggiunti, esatti
  const borders = el.offsetHeight - el.clientHeight
  const height = Math.min(el.scrollHeight + borders, 560)
  el.style.height = height + 'px'
  el.style.overflowY = el.scrollHeight + borders > 560 ? 'auto' : 'hidden'
}
const vGrow = { mounted: (el) => requestAnimationFrame(() => grow({ target: el })) }

function choose(url) {
  if (picker.value?.kind === 'richtext') picker.value.callback(url, { title: decodeURIComponent(url.split('/').pop()) })
  else emit('update:modelValue', url)
  picker.value = null
}
</script>

<template>
  <KSwitch v-if="def.type === 'boolean'" :id="id" :model-value="modelValue === true" :label="label" text="Acceso" @update:model-value="$emit('update:modelValue', $event)" />

  <fieldset v-else-if="def.type === 'select' && def.multiple" class="chips">
    <legend class="sr-only">{{ label }}</legend>
    <label v-for="(option, i) in def.options || []" :key="option" class="chip" :for="`${id}-${i}`">
      <input :id="`${id}-${i}`" type="checkbox" :checked="picked.includes(option)" @change="toggle(option, $event.target.checked)" />
      <span>{{ option }}</span>
    </label>
  </fieldset>

  <select v-else-if="def.type === 'select'" :id="id" class="input" :class="{ 'is-changed': changed }" :value="picked[0] || ''" @change="$emit('update:modelValue', $event.target.value)">
    <option value="">—</option>
    <option v-for="option in def.options || []" :key="option" :value="option">{{ option }}</option>
  </select>

  <div v-else-if="def.type === 'media'" class="media-field" :class="{ 'is-changed': changed }">
    <div class="media-preview">
      <img v-if="text && mediaKind(text) === 'image'" :src="mediaSrc(text, stagedNames)" :alt="`Anteprima di ${label}`" />
      <video v-else-if="text && mediaKind(text) === 'video'" :src="mediaSrc(text, stagedNames)" muted playsinline preload="metadata"></video>
      <span v-else-if="text" class="file-placeholder">PDF</span>
      <span v-else>Nessun file</span>
    </div>
    <div class="media-field-controls">
      <input :id="id" class="input" type="text" :value="text" placeholder="assets/uploads/…" :aria-label="`${label}: indirizzo del file`" @input="$emit('update:modelValue', $event.target.value)" />
      <button type="button" class="btn btn-white btn-small" @click="picker = { kind: 'field' }"><KIcon name="media" />Scegli</button>
      <button v-if="text" type="button" class="btn btn-white btn-small" @click="$emit('update:modelValue', '')">Togli</button>
    </div>
  </div>

  <RichText v-else-if="def.type === 'richtext'" :id="id" :label="label" :model-value="text" @update:model-value="$emit('update:modelValue', $event)" @pick-media="(callback) => (picker = { kind: 'richtext', callback })" />

  <div v-else-if="def.type === 'embed'" class="embed-field">
    <input :id="id" class="input mono" :class="{ 'is-changed': changed }" type="text" :value="text" placeholder="https://www.youtube.com/watch?v=… o il codice di incorporamento" @input="$emit('update:modelValue', $event.target.value)" />
    <p class="hint">Incolla l'indirizzo del video, del post o della mappa (YouTube, Vimeo, Instagram, TikTok, Google Maps…) o il suo codice di incorporamento. I siti ammessi sono in Impostazioni › Contenuti incorporati.</p>
  </div>

  <textarea v-else :id="id" v-grow class="input autogrow" :class="{ 'is-changed': changed }" rows="1" :value="text" @input="grow($event), $emit('update:modelValue', $event.target.value)"></textarea>

  <MediaPicker v-if="picker" :value="picker.kind === 'field' ? text : ''" :accept="accept" @choose="choose" @close="picker = null" />
</template>

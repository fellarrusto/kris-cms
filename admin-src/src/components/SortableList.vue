<script setup>
import { ref } from 'vue'

/**
 * Elenco riordinabile: si trascina dalla maniglia ([data-handle] nello slot) oppure, con il
 * focus sulla maniglia, con le frecce su e giù. Emette reorder con i nuovi id: chi lo usa
 * salva e, se il server non conferma, ricarica i dati (l'elenco torna com'era).
 */
const props = defineProps({
  items: { type: Array, required: true },
  disabled: Boolean,
})
const emit = defineEmits(['reorder'])

const dragging = ref(null)

function onPointerDown(event) {
  const handle = event.target.closest('[data-handle]')
  if (handle && !props.disabled) handle.closest('[data-id]').draggable = true
}
function onDragStart(event) {
  const row = event.target.closest?.('[data-id]')
  if (!row?.draggable) return event.preventDefault()
  dragging.value = Number(row.dataset.id)
  event.dataTransfer.effectAllowed = 'move'
  event.dataTransfer.setData('text/plain', row.dataset.id)
}
function onDrop(event) {
  const row = event.target.closest('[data-id]')
  if (dragging.value === null || !row) return
  event.preventDefault()
  const over = Number(row.dataset.id)
  if (over === dragging.value) return
  const after = event.clientY > row.getBoundingClientRect().top + row.offsetHeight / 2
  const ids = props.items.map((i) => i.id).filter((x) => x !== dragging.value)
  ids.splice(ids.indexOf(over) + (after ? 1 : 0), 0, dragging.value)
  emit('reorder', ids)
}
function onDragEnd(event) {
  event.target.closest?.('[data-id]')?.removeAttribute('draggable')
  dragging.value = null
}
function onKey(event) {
  const handle = event.target.closest('[data-handle]')
  if (!handle || props.disabled || !['ArrowUp', 'ArrowDown'].includes(event.key)) return
  event.preventDefault()
  const id = Number(handle.closest('[data-id]').dataset.id)
  const index = props.items.findIndex((i) => i.id === id)
  const to = index + (event.key === 'ArrowUp' ? -1 : 1)
  if (to < 0 || to >= props.items.length) return
  const ids = props.items.map((i) => i.id)
  ids.splice(index, 1)
  ids.splice(to, 0, id)
  emit('reorder', ids)
  // Il focus resta sulla maniglia dell'elemento spostato
  requestAnimationFrame(() => document.querySelector(`[data-id="${id}"] [data-handle]`)?.focus())
}
</script>

<template>
  <div class="rows" @pointerdown="onPointerDown" @dragstart="onDragStart" @dragover.prevent @drop="onDrop" @dragend="onDragEnd" @keydown="onKey">
    <div v-for="(entry, index) in items" :key="entry.id" :data-id="entry.id" class="row" :class="{ 'is-dragging': dragging === entry.id, 'is-hidden': entry.hidden }">
      <slot :item="entry" :index="index" />
    </div>
  </div>
</template>

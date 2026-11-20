<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

/**
 * Testo formattato con TinyMCE (caricato dalla pagina). Senza TinyMCE resta un campo
 * HTML semplice. Il server filtra comunque l'HTML al salvataggio (Kris\Template\Richtext).
 */
const props = defineProps({ modelValue: { type: String, default: '' }, id: { type: String, required: true }, label: { type: String, required: true } })
const emit = defineEmits(['update:modelValue', 'pick-media'])

const area = ref(null)
let editor = null

const dark = () => document.documentElement.dataset.theme === 'dark' || (!document.documentElement.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches)

function init(attempt = 0) {
  if (!window.tinymce) {
    // Lo script è caricato con defer: si aspetta un poco prima di arrendersi
    if (attempt < 20) setTimeout(() => init(attempt + 1), 150)
    return
  }
  window.tinymce.init({
    target: area.value,
    height: 300,
    menubar: false,
    skin: dark() ? 'oxide-dark' : 'oxide',
    content_css: dark() ? 'dark' : 'default',
    // La stessa scrollbar dell'editor anche dentro l'iframe del testo
    content_style: `::-webkit-scrollbar{width:10px;height:10px}::-webkit-scrollbar-track,::-webkit-scrollbar-corner{background:transparent}::-webkit-scrollbar-button{display:none;width:0;height:0}::-webkit-scrollbar-thumb{background:${dark() ? 'rgba(237,237,234,.22)' : 'rgba(23,23,26,.22)'};border-radius:999px;border:3px solid transparent;background-clip:padding-box}@supports (-moz-appearance:none){html{scrollbar-width:thin;scrollbar-color:${dark() ? 'rgba(237,237,234,.22)' : 'rgba(23,23,26,.22)'} transparent}}`,
    plugins: 'image link lists code fullscreen',
    toolbar: 'undo redo | blocks | bold italic | bullist numlist | link image | code fullscreen',
    file_picker_callback: (callback) => emit('pick-media', callback),
    setup(ed) {
      editor = ed
      ed.on('change input undo redo', () => emit('update:modelValue', ed.getContent()))
    },
  })
}

onMounted(() => init())
onBeforeUnmount(() => editor?.remove())
watch(
  () => props.modelValue,
  (value) => {
    if (editor && value !== editor.getContent()) editor.setContent(value || '')
  }
)
</script>

<template>
  <textarea :id="id" ref="area" class="input" rows="8" :aria-label="label" :value="modelValue" @input="$emit('update:modelValue', $event.target.value)"></textarea>
</template>

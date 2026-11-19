<script setup>
/** Interruttore acceso/spento, con il testo visibile accanto o solo per i lettori di schermo. */
defineProps({
  modelValue: Boolean,
  label: { type: String, required: true },
  text: { type: String, default: '' },
  disabled: Boolean,
  id: { type: String, default: undefined },
})
defineEmits(['update:modelValue'])
</script>

<template>
  <label class="switch" :class="{ 'switch-labeled': text }">
    <span v-if="text" class="switch-text">{{ text }}</span>
    <input
      :id="id"
      type="checkbox"
      role="switch"
      :checked="modelValue"
      :disabled="disabled"
      :aria-label="text ? undefined : label"
      @change="$emit('update:modelValue', $event.target.checked)"
    />
    <span class="switch-track" aria-hidden="true"></span>
  </label>
</template>

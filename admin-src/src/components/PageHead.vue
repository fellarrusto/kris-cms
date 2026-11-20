<script setup>
// Titolo e strumenti della pagina, mostrati nella barra in alto (AppTopbar): il titolo a sinistra,
// gli strumenti prima di quelli comuni. Senza PageHead la barra mostra il nome della sezione.
</script>

<template>
  <Teleport defer to="#page-title"><slot /></Teleport>
  <Teleport v-if="$slots.actions" defer to="#page-actions"><slot name="actions" /></Teleport>
</template>

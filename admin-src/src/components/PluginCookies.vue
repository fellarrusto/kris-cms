<script setup>
import { computed } from 'vue'
import { useSessionStore } from '@/stores/session'

/** Il registro dei cookie dei plugin accesi, in sola lettura (Privacy e cookie). */
const session = useSessionStore()
const cookies = computed(() => session.data?.plugins?.cookies || [])
const domains = computed(() => session.data?.plugins?.domains || [])
const categories = { technical: 'Tecnico', thirdparty: 'Terze parti', statistics: 'Statistiche', marketing: 'Marketing' }
</script>

<template>
  <section v-if="cookies.length || domains.length" class="box">
    <h2>Cookie e domini dei plugin</h2>
    <p class="hint">Dichiarati dai plugin accesi: riportali nell'informativa sui cookie. Kris non li verifica sul sito.</p>
    <div v-if="cookies.length" style="overflow-x: auto">
      <table class="table-list">
        <thead><tr><th scope="col">Cookie</th><th scope="col">Categoria</th><th scope="col">Durata</th><th scope="col">Fornitore e finalità</th></tr></thead>
        <tbody>
          <tr v-for="c in cookies" :key="c.plugin + c.name">
            <td class="mono">{{ c.name }}</td>
            <td>{{ categories[c.category] }}</td>
            <td>{{ c.duration }}</td>
            <td>{{ c.provider }}<span v-if="c.purpose"> · {{ c.purpose }}</span><div class="muted">{{ c.plugin }}</div></td>
          </tr>
        </tbody>
      </table>
    </div>
    <p v-if="domains.length" class="hint">Domini esterni: <span class="mono">{{ domains.join(', ') }}</span></p>
  </section>
</template>

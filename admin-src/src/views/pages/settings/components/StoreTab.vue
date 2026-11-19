<script setup>
import { onMounted, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import SiteService from '@/services/SiteService'
import { useUiStore } from '@/stores/ui'

/** Dove Kris conserva i dati del sito; il passaggio lo fa lo sviluppatore con tools/store.php. */
const ui = useUiStore()
const data = ref(null)
onMounted(async () => {
  try {
    data.value = await SiteService.store()
  } catch (e) {
    ui.toast(e.message)
  }
})
</script>

<template>
  <section class="card">
    <header class="card-header"><span class="collection-icon"><KIcon name="code" /></span><div><h2>Archivio dei dati</h2><p>Dove Kris conserva struttura, contenuti e impostazioni del sito.</p></div></header>
    <div v-if="data" class="card-body">
      <div class="setting-row static">
        <span><strong>Archivio in uso</strong><small>{{ data.driver === 'json' ? 'File JSON nella cartella data/: va bene per quasi tutti i siti.' : 'Database: utile con molti contenuti o scritture frequenti.' }}</small></span>
        <span class="setting-value">{{ data.driver === 'json' ? 'File' : 'Database' }}</span>
      </div>
      <div class="setting-row static"><span><strong>Contenuti</strong><small>Pagine, blocchi ed elementi delle raccolte.</small></span><span class="setting-value">{{ data.contents }}</span></div>
      <div class="setting-row static"><span><strong>Copie di sicurezza</strong><small>Prima di ogni scrittura resta una copia della versione precedente.</small></span><span class="setting-value">{{ data.driver === 'json' ? 'data/backups/' : 'nel database' }}</span></div>
      <div class="notice"><strong>Il passaggio fra file e database lo fa lo sviluppatore.</strong><p>Con <code>php tools/store.php convert</code>: copia tutto, lo rilegge e lo confronta prima di cambiare archivio, e quello vecchio resta come copia.</p></div>
    </div>
  </section>
</template>

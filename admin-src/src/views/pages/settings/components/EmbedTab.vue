<script setup>
import { ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { useUiStore } from '@/stores/ui'

/** I siti da cui i campi «Contenuto incorporato» accettano video, post e mappe (settings.embed.domains). */
const DEFAULTS = ['youtube.com', 'youtube-nocookie.com', 'vimeo.com', 'instagram.com', 'tiktok.com', 'google.com', 'spotify.com']
const session = useSessionStore()
const site = useSiteStore()
const ui = useUiStore()

const domains = ref((site.settings.embed?.domains || DEFAULTS).join('\n'))
const busy = ref(false)

async function save() {
  busy.value = true
  try {
    const list = domains.value
      .split(/[\s,]+/)
      .map((d) => d.trim())
      .filter(Boolean)
    await site.apply([{ op: 'set_embed_domains', domains: list }])
    domains.value = (site.settings.embed?.domains || list).join('\n')
    ui.toast('Siti ammessi salvati.')
  } catch (e) {
    ui.toast(e.message)
  }
  busy.value = false
}
</script>

<template>
  <form class="card" @submit.prevent="save">
    <header class="card-header">
      <span class="collection-icon"><KIcon name="globe" /></span>
      <div>
        <h2>Contenuti incorporati</h2>
        <p>I siti da cui i campi «Contenuto incorporato» accettano video, post e mappe.</p>
      </div>
    </header>
    <div class="card-body collection-info-form">
      <label class="field-label"
        >Siti ammessi, uno per riga<textarea v-model="domains" class="input mono" rows="8" :disabled="session.isStaging || session.locked"></textarea>
      </label>
      <p class="hint">
        Vale anche per i sottodomini: youtube.com ammette www.youtube.com. Un sito tolto dalla lista smette di comparire nelle pagine.
        I contenuti incorporati possono usare cookie del sito che li ospita: indicalo nella cookie policy.
      </p>
      <p v-if="session.isStaging" class="hint">Si cambiano sul sito pubblico: passa a «Sito pubblico».</p>
      <button class="btn btn-primary btn-small" :disabled="busy || session.isStaging || session.locked">Salva</button>
    </div>
  </form>
</template>

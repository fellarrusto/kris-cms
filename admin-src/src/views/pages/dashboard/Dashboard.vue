<script setup>
import { computed, ref } from 'vue'
import KIcon from '@/components/KIcon.vue'
import PluginWidget from '@/components/PluginWidget.vue'
import { useSessionStore } from '@/stores/session'
import { useSiteStore } from '@/stores/site'
import { isNewestFirst, isSingle, typeDescription } from '@/lib/model'

/** Contenuti: le pagine e i blocchi (contenuti unici) aprono la modifica, le raccolte la loro lista. */
const session = useSessionStore()
const site = useSiteStore()
const search = ref('')

// Un tipo reso da una pagina dello schema è una pagina; un contenuto unico che non lo è, un blocco condiviso
const paged = computed(() => new Set(Object.values(site.schema.pages || {}).map((p) => p?.type)))
const tiles = computed(() =>
  site.userTypes
    .filter((t) => !search.value || `${site.label(t.name)} ${t.name}`.toLowerCase().includes(search.value.toLowerCase()))
    .map((t) => {
      const list = site.items(t.name)
      const single = isSingle(site.schema, t.name)
      return {
        name: t.name,
        single,
        label: site.label(t.name),
        description: typeDescription(site.schema, t.name),
        count: list.length,
        hidden: list.filter((i) => i.hidden).length,
        fields: (t.fields || []).length,
        tag: single ? (paged.value.has(t.name) ? 'Pagina' : 'Blocco') : isNewestFirst(site.schema, t.name) ? 'Articoli' : 'Raccolta',
        icon: single ? 'page' : isNewestFirst(site.schema, t.name) ? 'posts' : 'list',
      }
    })
)
const singles = computed(() => tiles.value.filter((t) => t.single))
const widgets = computed(() => (session.data?.plugins?.active || []).filter((p) => p.dashboard && p.module))
const collections = computed(() => tiles.value.filter((t) => !t.single))
const target = (t) => (t.single && t.count ? { name: 'edit', params: { type: t.name, id: site.items(t.name)[0].id } } : { name: 'collection', params: { type: t.name } })
</script>

<template>
  <div class="container">
    <div class="page-head">
      <div>
        <h1>Cosa vuoi modificare?</h1>
        <p class="sub">{{ session.isStaging ? 'Le modifiche restano in attesa finché non le pubblichi.' : 'I contenuti salvati sono subito visibili sul sito.' }}</p>
      </div>
      <label v-if="site.userTypes.length" class="search"><KIcon name="search" /><input v-model="search" type="search" placeholder="Cerca un contenuto" aria-label="Cerca un contenuto" /></label>
    </div>

    <template v-if="singles.length">
      <div class="section-head"><h2>Pagine e blocchi</h2><span>Contenuti unici: si aprono direttamente in modifica</span></div>
      <div class="grid">
        <router-link v-for="t in singles" :key="t.name" class="tile" :to="target(t)">
          <div class="tile-head"><span class="ic"><KIcon :name="t.icon" /></span><span class="tile-name">{{ t.label }}</span></div>
          <p v-if="t.description" class="tile-text">{{ t.description }}</p>
          <div class="tile-foot"><span>{{ t.fields }} {{ t.fields === 1 ? 'campo' : 'campi' }}</span><span class="tag">{{ t.tag }}</span></div>
        </router-link>
      </div>
    </template>

    <template v-if="collections.length">
      <div class="section-head"><h2>Raccolte</h2><span>Elenchi di contenuti che puoi ordinare</span></div>
      <div class="grid">
        <router-link v-for="t in collections" :key="t.name" class="tile" :to="target(t)">
          <div class="tile-head"><span class="ic"><KIcon :name="t.icon" /></span><span class="tile-name">{{ t.label }}</span></div>
          <p v-if="t.description" class="tile-text">{{ t.description }}</p>
          <div class="tile-foot">
            <span><span class="num">{{ t.count }}</span> {{ t.count === 1 ? 'contenuto' : 'contenuti' }}<template v-if="t.hidden"> · {{ t.hidden }} {{ t.hidden === 1 ? 'sospeso' : 'sospesi' }}</template></span>
            <span class="tag">{{ t.tag }}</span>
          </div>
        </router-link>
      </div>
    </template>

    <template v-if="widgets.length && !search">
      <div class="section-head"><h2>Dai plugin</h2><span>Riquadri dei plugin accesi</span></div>
      <div class="grid"><PluginWidget v-for="p in widgets" :key="p.name" :plugin="p" /></div>
    </template>

    <div v-if="!tiles.length" class="empty-state">
      <h2>{{ site.userTypes.length ? 'Nessun contenuto con questo nome.' : 'Il primo contenuto inizia da qui.' }}</h2>
      <p>{{ site.userTypes.length ? 'Prova un altro nome o svuota la ricerca.' : 'Crea una raccolta in Struttura e scegli i campi che vuoi gestire.' }}</p>
      <router-link v-if="!site.userTypes.length && !session.isStaging" class="btn btn-primary" :to="{ name: 'structure' }"><KIcon name="plus" />Nuova raccolta</router-link>
    </div>
  </div>
</template>

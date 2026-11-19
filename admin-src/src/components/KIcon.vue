<script setup>
// Icone dell'editor: tratto da 1,6 su una griglia di 24
import { computed } from 'vue'

const props = defineProps({ name: { type: String, required: true } })

const PATHS = {
  grid: `<rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/>`,
  media: `<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="M20 16l-5-5-8 8"/>`,
  list: `<path d="M5 7h14M5 12h14M5 17h9"/>`,
  page: `<path d="M6 3h9l4 4v14H6z"/>`,
  block: `<path d="M4 17h16"/><rect x="4" y="5" width="16" height="8" rx="2"/>`,
  posts: `<path d="M5 5h14v14H5zM8 9h8M8 13h8M8 16h5"/>`,
  globe: `<circle cx="12" cy="12" r="8"/><path d="M4 12h16M12 4c2.5 3 2.5 13 0 16M12 4c-2.5 3-2.5 13 0 16"/>`,
  shield: `<path d="M12 3l7 3v6c0 4-3 7-7 9-4-2-7-5-7-9V6z"/>`,
  notfound: `<circle cx="12" cy="12" r="8"/><path d="M9 9l6 6M15 9l-6 6"/>`,
  wip: `<path d="M4 20h16M7 20V9l5-4 5 4v11"/>`,
  code: `<path d="M8 6l-5 6 5 6M16 6l5 6-5 6"/>`,
  link: `<path d="M10 14a4 4 0 006 0l3-3a4 4 0 00-6-6l-1 1M14 10a4 4 0 00-6 0l-3 3a4 4 0 006 6l1-1"/>`,
  settings: `<circle cx="12" cy="12" r="3"/><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>`,
  logout: `<path d="M14 4h5v16h-5M10 8l-4 4 4 4M6 12h10"/>`,
  publish: `<path d="M12 4v11M7 9l5-5 5 5M5 20h14"/>`,
  clock: `<circle cx="12" cy="12" r="8"/><path d="M12 8v4l3 2"/>`,
  external: `<path d="M14 5h5v5M19 5l-8 8M17 14v5H5V7h5"/>`,
  search: `<circle cx="11" cy="11" r="6"/><path d="M20 20l-4-4"/>`,
  plus: `<path d="M12 5v14M5 12h14"/>`,
  back: `<path d="M15 6l-6 6 6 6"/>`,
  arrow: `<path d="M9 6l6 6-6 6"/>`,
  check: `<path d="m5 12 4 4L19 6"/>`,
  close: `<path d="m6 6 12 12M6 18 18 6"/>`,
  trash: `<path d="M4 6h16M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7M14 10v7"/>`,
  up: `<path d="m6 15 6-6 6 6"/>`,
  down: `<path d="m6 9 6 6 6-6"/>`,
  drag: `<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>`,
  upload: `<path d="M12 16V4M7 9l5-5 5 5M4 15v5h16v-5"/>`,
  copy: `<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V5a1 1 0 00-1-1H5a1 1 0 00-1 1v10a1 1 0 001 1h3"/>`,
  theme: `<path d="M12 3a9 9 0 100 18 7 7 0 010-18z"/>`,
  warning: `<path d="M12 4l9 16H3z"/><path d="M12 10v4M12 17v.5"/>`,
  agent: `<path d="M12 3l1.8 4.7L18.5 9.5l-4.7 1.8L12 16l-1.8-4.7L5.5 9.5l4.7-1.8z"/><path d="M18 15l.8 2.2L21 18l-2.2.8L18 21l-.8-2.2L15 18l2.2-.8z"/>`,
  language: `<path d="M4 6h10M9 4v2c0 4-2 8-5 10M6 10c1 2 3 4 6 5"/><path d="M13 20l4-9 4 9M14.5 17h5"/>`,
  version: `<path d="M4 12a8 8 0 0114-5.3M20 12a8 8 0 01-14 5.3"/><path d="M18 3v4h-4M6 21v-4h4"/>`,
  user: `<circle cx="12" cy="8" r="4"/><path d="M4 21c1-4 4-6 8-6s7 2 8 6"/>`,
}

const inner = computed(() => PATHS[props.name] || PATHS.page)
</script>

<template>
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="inner"></svg>
</template>

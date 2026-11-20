/**
 * Voci fisse della barra laterale (docs/2.0/editor-specifiche.md, 4). Le raccolte con
 * «i nuovi in cima» e quelle dei plugin si aggiungono in AppSidebar dai dati del sito.
 */
export const devItems = [
  { label: 'Struttura', icon: 'code', to: { name: 'structure' } },
  { label: 'Indirizzi', icon: 'link', to: { name: 'addresses' } },
]

/** Le sezioni di Kris (tipi kris_) e la scheda che le modifica. */
export const RESERVED_HOME = {
  kris_site: { name: 'settings', params: { tab: 'sito' } },
  kris_legal: { name: 'settings', params: { tab: 'privacy' } },
  kris_notfound: { name: 'system', params: { tab: '404' } },
  kris_wip: { name: 'system', params: { tab: 'lavori' } },
}

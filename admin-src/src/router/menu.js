import { RESERVED_VIEWS } from '@/lib/model'

/**
 * Voci fisse della barra laterale (docs/2.0/editor-specifiche.md, 4). Le raccolte con
 * «i nuovi in cima» e quelle dei plugin si aggiungono in AppSidebar dai dati del sito.
 * `admin: true` nasconde la voce a chi non è amministratore: la protezione vera è meta.admin sulla rotta.
 */
export const siteItems = Object.entries(RESERVED_VIEWS).map(([type, v]) => ({
  label: v.label,
  icon: v.icon,
  to: { name: 'edit', params: { type, id: 0 } },
  type,
}))

export const devItems = [
  { label: 'Struttura', icon: 'code', to: { name: 'structure' } },
  { label: 'Indirizzi', icon: 'link', to: { name: 'addresses' } },
]

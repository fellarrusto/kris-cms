/**
 * Punto unico della configurazione dell'app.
 * REGOLA: nessun file fuori da qui legge `import.meta.env` o `window.KRIS` direttamente.
 */

// Scritto da kris/editor/index.php nella pagina: token, indirizzi
const boot = window.KRIS || {}

/** L'API dell'editor: index.php?api=... accanto alla pagina (in sviluppo, attraverso il proxy). */
export const API_URL = import.meta.env.VITE_API_URL || boot.api || 'index.php'

/** La cartella dell'editor compilato, per i file dei plugin e le immagini dell'app. */
export const ASSET_BASE = boot.assets || ''

export const INITIAL_CSRF = boot.csrf || ''

export const ROLES = {
  ADMIN: 'admin',
  BASE: 'base',
}

export const MODES = {
  LIVE: 'live',
  STAGING: 'staging',
}

import { API_URL, INITIAL_CSRF } from '@/config'

/**
 * Chiamate all'API dell'editor (kris/editor/api.php). Ogni service importa questo, mai fetch diretto.
 * GET per leggere, POST con JSON e token CSRF per scrivere. Un errore diventa ApiError con il
 * messaggio da mostrare: la pagina lo cattura e lo mostra.
 */
let csrf = INITIAL_CSRF

export class ApiError extends Error {
  constructor(message, status, data = {}) {
    super(message)
    this.status = status
    this.data = data
  }
}

export function setCsrf(token) {
  if (token) csrf = token
}

async function request(name, { body, query } = {}) {
  const params = new URLSearchParams({ api: name, ...(query || {}) })
  let response
  try {
    response = await fetch(`${API_URL}?${params}`, {
      method: body === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: body === undefined ? {} : { 'Content-Type': 'application/json', 'X-Kris-Csrf': csrf },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Connessione interrotta: non possiamo confermare la richiesta. Controlla la rete e riprova.', 0)
  }
  const data = await response.json().catch(() => null)
  if (data?.csrf) setCsrf(data.csrf)
  if (response.status === 401) {
    // Sessione finita: la pagina dell'editor mostra l'accesso
    window.location.reload()
    throw new ApiError('Sessione scaduta: accedi di nuovo.', 401)
  }
  if (!data) throw new ApiError('Risposta non valida dal server: ricarica la pagina e riprova.', response.status)
  if (!response.ok || data.ok === false) throw new ApiError(data.error || 'Richiesta non riuscita.', response.status, data)
  return data
}

/** Carica un file con un normale modulo multipart (upload.php accanto all'editor). */
export async function upload(file) {
  const body = new FormData()
  body.append('file', file)
  body.append('csrf', csrf)
  const response = await fetch(API_URL.replace(/index\.php$/, 'upload.php'), {
    method: 'POST',
    body,
    credentials: 'same-origin',
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
  }).catch(() => null)
  if (!response) throw new ApiError('Connessione interrotta durante il caricamento.', 0)
  const data = await response.json().catch(() => null)
  if (!response.ok || !data?.url) throw new ApiError(data?.error || (response.status === 403 ? 'Sessione scaduta: accedi di nuovo.' : 'Caricamento non riuscito.'), response.status)
  return data
}

/** Le operazioni di aggiornamento di Kris (update.php): modulo con il token. */
export async function updateOp(op, fields = {}) {
  const body = new FormData()
  body.append('op', op)
  body.append('csrf', csrf)
  for (const [key, value] of Object.entries(fields)) body.append(key, value)
  const response = await fetch(API_URL.replace(/index\.php$/, 'update.php'), { method: 'POST', body, credentials: 'same-origin', cache: 'no-store' }).catch(() => null)
  if (!response) throw new ApiError('Connessione interrotta: ricarica la pagina tra qualche minuto per vedere com\'è andata.', 0)
  const data = await response.json().catch(() => null)
  if (!data) throw new ApiError('Risposta non valida: ricarica la pagina tra qualche minuto.', response.status)
  return data
}

export const api = {
  get: (name, query) => request(name, { query }),
  post: (name, body = {}, query) => request(name, { body, query }),
}

export default api

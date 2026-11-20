import api from '@/plugins/api'

/** Chi è collegato, modalità, stato del framework e delle modifiche in attesa. */
const SessionService = {
  get: () => api.get('session'),
  setMode: (mode) => api.post('mode', { mode }),
}

export default SessionService

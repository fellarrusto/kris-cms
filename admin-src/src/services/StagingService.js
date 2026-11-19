import api from '@/plugins/api'

/** Le modifiche in attesa: riepilogo, pubblicazione, scarto, annullamento, approvazione del codice. */
const StagingService = {
  get: () => api.get('staging'),
  promote: (force = false) => api.post('staging-action', { action: 'promote', force }),
  discard: () => api.post('staging-action', { action: 'discard' }),
  undo: () => api.post('staging-action', { action: 'undo' }),
  approve: (fingerprint) => api.post('staging-action', { action: 'approve', fingerprint }),
}

export default StagingService

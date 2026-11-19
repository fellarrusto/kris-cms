import api, { updateOp } from '@/plugins/api'

/** Versione di Kris, aggiornamenti (update.php) e migrazioni del formato dei dati. */
const VersionService = {
  get: () => api.get('version'),
  migrate: () => api.post('migrate'),
  op: (op, fields) => updateOp(op, fields),
}

export default VersionService

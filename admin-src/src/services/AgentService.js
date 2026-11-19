import api from '@/plugins/api'

/** L'assistente AI (Kris Agent): abbinamento e chi può usarlo. */
const AgentService = {
  get: () => api.get('agent'),
  pair: (code) => api.post('agent-action', { action: 'pair', code }),
  toggle: (enabled) => api.post('agent-action', { action: 'toggle', enabled }),
  unpair: () => api.post('agent-action', { action: 'unpair' }),
  grant: (userId, status) => api.post('agent-action', { action: 'grant', user_id: userId, status }),
  reset: () => api.post('agent-action', { action: 'reset' }),
}

export default AgentService

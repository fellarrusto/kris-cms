import api from '@/plugins/api'

/** Gli utenti dell'editor: solo per gli amministratori. */
const UserService = {
  list: () => api.get('users'),
  create: (user) => api.post('user', { action: 'create', ...user }),
  block: (name) => api.post('user', { action: 'block', name }),
  unblock: (name) => api.post('user', { action: 'unblock', name }),
  remove: (name) => api.post('user', { action: 'delete', name }),
  setRole: (name, role) => api.post('user', { action: 'role', name, role }),
  setPassword: (name, password) => api.post('user', { action: 'password', name, password }),
}

export default UserService

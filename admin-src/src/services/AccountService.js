import api from '@/plugins/api'

/** L'utente collegato: la sua email di recupero e la sua password. */
const AccountService = {
  setEmail: (email, currentPassword) => api.post('account', { action: 'email', email, current_password: currentPassword }),
  setPassword: (password, password2, currentPassword) => api.post('account', { action: 'password', password, password2, current_password: currentPassword }),
  testEmail: () => api.post('account', { action: 'test-email' }),
  later: () => api.post('account', { action: 'later' }),
}

export default AccountService

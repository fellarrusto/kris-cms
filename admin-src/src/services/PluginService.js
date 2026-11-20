import api from '@/plugins/api'

/** Plugin: installati, catalogo ufficiale, installazione, impostazioni (kris/editor/api.php). */
const PluginService = {
  list: () => api.get('plugins'),
  catalog: () => api.get('plugin-catalog'),
  releases: (name) => api.get('plugin-catalog', { name }),
  install: (name, version, password) => api.post('plugin-action', { action: 'install', name, version, password }),
  activate: (name) => api.post('plugin-action', { action: 'activate', name }),
  deactivate: (name) => api.post('plugin-action', { action: 'deactivate', name }),
  remove: (name, password, deleteData) => api.post('plugin-action', { action: 'remove', name, password, delete_data: deleteData }),
  settings: (name) => api.get('plugin-settings', { name }),
  saveSettings: (name, values) => api.post('plugin-settings', { name, values }),
}

export default PluginService

import api from '@/plugins/api'

/** Struttura, contenuti e impostazioni del sito nella modalità in uso; ogni scrittura è un'operazione. */
const SiteService = {
  get: () => api.get('site'),
  apply: (ops) => api.post('ops', { ops }),
  saveStructure: (type, fields, confirm = false) => api.post('structure', { type, fields, confirm }),
  saveLanguages: (languages, main) => api.post('languages', { languages, main }),
  saveUploadSettings: (settings) => api.post('upload-settings', settings),
  saveSeo: (seo) => api.post('seo', { seo }),
  saveRedirects: (redirects) => api.post('redirects', { redirects }),
  store: () => api.get('store'),
}

export default SiteService

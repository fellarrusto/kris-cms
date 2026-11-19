import api, { upload } from '@/plugins/api'

const MediaService = {
  list: async () => (await api.get('media')).files,
  upload: (file) => upload(file),
  remove: (name) => api.post('media-delete', { name }),
  optimize: () => api.post('media-optimize'),
}

export default MediaService

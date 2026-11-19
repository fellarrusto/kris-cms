/** Peso leggibile: «412 KB», «1,2 MB». */
export function formatSize(bytes) {
  if (bytes >= 1048576) return (bytes / 1048576).toLocaleString('it-IT', { maximumFractionDigits: 1 }) + ' MB'
  return Math.max(1, Math.round(bytes / 1024)) + ' KB'
}

/** Data e ora in italiano. */
export function formatDate(value) {
  const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString('it-IT', { day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })
}

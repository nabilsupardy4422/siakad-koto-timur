export function formatIdentityDate(value) {
  if (value === null || value === undefined || value === '') return value
  if (typeof value !== 'string') return 'Tanggal tidak valid'
  const match = /^(\d{4})-(\d{2})-(\d{2})(?:$|T| )/.exec(value)
  if (!match) return 'Tanggal tidak valid'
  const day = `${match[1]}-${match[2]}-${match[3]}`
  const date = new Date(`${day}T00:00:00Z`)
  if (!Number.isFinite(date.getTime()) || date.toISOString().slice(0, 10) !== day) return 'Tanggal tidak valid'
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'long', timeZone: 'UTC' }).format(date)
}

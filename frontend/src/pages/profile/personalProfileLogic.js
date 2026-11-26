export const contactFields = ['no_telepon', 'alamat']
export function contactChanges(original, draft, version) {
  const changes = {}
  for (const field of contactFields) {
    const value = String(draft[field] ?? '').trim() || null
    const previous = String(original[field] ?? '').trim() || null
    if (value !== previous) changes[field] = value
  }
  return Object.keys(changes).length ? {profile_version: version, ...changes} : null
}
export function profileValue(value) {
  return value === null || value === undefined || String(value).trim() === '' ? 'Belum diisi' : String(value)
}
export function genderLabel(value) {
  return ['L','Laki-laki'].includes(value) ? 'Laki-laki' : ['P','Perempuan'].includes(value) ? 'Perempuan' : profileValue(value)
}
export function placementLabel(value) {
  if (!value) return 'Konteks kelas belum tersedia'
  return ({effective: value.class_name || 'Nama kelas belum tersedia', unassigned: 'Belum ditempatkan', future: 'Penempatan belum berlaku', historical: 'Penempatan telah berakhir', conflict: 'Konflik penempatan · hubungi TU', period_unavailable: 'Periode belum tersedia'})[value.status] || 'Konteks kelas belum tersedia'
}
export { passwordChecks } from '../../components/security/passwordPolicy.js'

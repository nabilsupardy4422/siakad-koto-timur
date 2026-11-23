// Server-scoped, read-only dashboard contract. Never infer student access in React.
export function parseStudentDashboard(payload) {
  const data = payload?.data
  if (!data || typeof data !== 'object' || Array.isArray(data)) throw new Error('Respons dashboard siswa tidak valid.')
  if (!['active', 'unassigned', 'scheduled', 'ended'].includes(data.placement_status)) throw new Error('Status penempatan tidak valid.')
  if (!data.academic_year || !Number.isInteger(data.academic_year.id) || !/^\d{4}-\d{2}-\d{2}$/.test(data.as_of || '')) throw new Error('Periode dashboard tidak valid.')
  for (const key of ['today_schedule', 'upcoming_assignments', 'subjects', 'recent_materials']) {
    if (!Array.isArray(data[key])) throw new Error(`Daftar ${key} tidak valid.`)
  }
  const s = data.assignment_summary
  if (!s || !['total', 'pending', 'submitted', 'overdue', 'late_submitted'].every(key => Number.isInteger(s[key]) && s[key] >= 0)) throw new Error('Ringkasan tugas tidak valid.')
  if (s.total !== s.pending + s.submitted || s.overdue > s.pending || s.late_submitted > s.submitted) throw new Error('Ringkasan tugas tidak konsisten.')
  if (data.placement_status !== 'active' && (data.class !== null || data.today_schedule.length || data.upcoming_assignments.length || data.subjects.length || data.recent_materials.length || s.total)) throw new Error('Data di luar penempatan aktif ditolak.')
  const ids = new Set()
  for (const row of data.upcoming_assignments) {
    if (!Number.isInteger(row.id) || ids.has(row.id) || !['pending', 'overdue'].includes(row.status)) throw new Error('Tugas dashboard tidak valid.')
    ids.add(row.id)
  }
  return data
}

export function formatWibDate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return '—'
  const [y, m, d] = value.split('-').map(Number)
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)))
}

export function formatDeadline(value) {
  if (!value) return 'Tanpa tenggat'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return 'Tenggat tidak tersedia'
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta', hour12: false }).format(date) + ' WIB'
}

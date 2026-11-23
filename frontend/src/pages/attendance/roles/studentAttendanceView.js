// Pure presentation helpers for Student Attendance V3. No API requests or authorization here.
export const timeText = value => String(value ?? '—').slice(0, 5)
export const initials = name => String(name || 'MP').trim().split(/\s+/).slice(0, 2).map(word => word[0]).join('').toUpperCase()
export const teacherNames = subject => [...new Set((subject?.schedules || []).map(s => s.teacher).filter(Boolean))]
export const getSummary = subject => subject?.summary || {}
export const periodText = year => year ? `${year.tahun_mulai}/${year.tahun_selesai} · ${year.semester === 'GENAP' ? 'Genap' : 'Ganjil'}` : 'Belum tersedia'
export const textStatus = status => ({ active: 'Penempatan aktif', scheduled: 'Belum dimulai', ended: 'Sudah berakhir' }[status] || 'Belum ditempatkan')
export const totalSummary = subjects => (subjects || []).reduce((acc, subject) => {
 for (const key of ['total', 'HADIR', 'IZIN', 'SAKIT', 'ALPA']) acc[key] += Number(getSummary(subject)[key]) || 0
 return acc
}, { total: 0, HADIR: 0, IZIN: 0, SAKIT: 0, ALPA: 0 })
export const presentPercentage = totals => totals.total ? Math.round(totals.HADIR / totals.total * 100) : null
export const isInvalidDateRange = (from, to) => Boolean(from && to && from > to)

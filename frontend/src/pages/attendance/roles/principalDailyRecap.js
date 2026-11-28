/**
 * Read-only projection of GET /attendance/class-recap.
 * A daily classroom recap counts each active student once per date,
 * unlike the per-meeting attendance charts on the dashboard.
 */
const STATUSES = ['hadir', 'izin', 'sakit', 'alpa', 'belum_ada_data']

function strictCount(value) {
  if (typeof value === 'number' && Number.isSafeInteger(value) && value >= 0) return value
  if (typeof value === 'string' && /^(0|[1-9]\d*)$/.test(value)) {
    const parsed = Number(value)
    if (Number.isSafeInteger(parsed)) return parsed
  }
  return null
}

function strictId(value) {
  const count = strictCount(value)
  return count !== null && count > 0 ? count : null
}

export function isISODate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const parsed = new Date(`${value}T00:00:00Z`)
  return !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value
}

export function todayWIB(now = new Date()) {
  if (!(now instanceof Date) || Number.isNaN(now.getTime())) throw new Error('Waktu tidak valid.')
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-US', {
    timeZone: 'Asia/Jakarta', year: 'numeric', month: '2-digit', day: '2-digit',
  }).formatToParts(now).filter(part => part.type !== 'literal').map(part => [part.type, part.value]))
  return `${parts.year}-${parts.month}-${parts.day}`
}

const LEVELS = { '10': 'X', '11': 'XI', '12': 'XII' }

export function principalClassLabel(row) {
  const name = typeof row?.nama_kelas === 'string' ? row.nama_kelas.trim() : ''
  const level = LEVELS[String(row?.tingkat ?? '')] || String(row?.tingkat ?? '').trim()
  if (!name) return 'Kelas tanpa nama'
  if (!level || /^(X|XI|XII|10|11|12)(\s|[-–])/i.test(name)) return name
  return `${level} ${name}`
}

export function normalizePrincipalDailyRecap(payload, { yearId, date, semester } = {}) {
  if (!strictId(yearId) || !isISODate(date) || !['GANJIL', 'GENAP'].includes(semester)) {
    throw new Error('Filter periode rekap presensi tidak valid.')
  }
  if (!payload || !Array.isArray(payload.data) || !payload.meta) {
    throw new Error('Respons rekap presensi tidak sesuai kontrak API.')
  }
  const { meta } = payload
  if (
    strictId(meta.academic_year_id) !== strictId(yearId) ||
    meta.date !== date ||
    meta.semester !== semester ||
    strictCount(meta.total_classes) !== payload.data.length
  ) {
    throw new Error('Konteks rekap presensi tidak sesuai periode yang diminta.')
  }
  const seen = new Set()
  const rows = payload.data.map(row => {
    const id = strictId(row?.class_id)
    const name = typeof row?.nama_kelas === 'string' ? row.nama_kelas.trim() : ''
    if (!id || !name || seen.has(id)) throw new Error('Identitas kelas rekap tidak valid atau duplikat.')
    seen.add(id)
    const total = strictCount(row.total_siswa)
    const counts = Object.fromEntries(STATUSES.map(key => [key, strictCount(row[key])]))
    if (total === null || Object.values(counts).some(value => value === null)) {
      throw new Error('Jumlah presensi mengandung nilai tidak valid.')
    }
    const sum = Object.values(counts).reduce((a, b) => a + b, 0)
    if (sum !== total) throw new Error('Total presensi tidak sesuai jumlah siswa pada kelas.')
    const recorded = total - counts.belum_ada_data
    return {
      id, label: principalClassLabel(row), tingkat: row.tingkat,
      total_siswa: total, ...counts, recorded,
      completion: total > 0 ? recorded / total * 100 : null,
    }
  })
  return { rows, meta: { date, academic_year_id: strictId(yearId), semester } }
}

export function principalDailyTotals(rows) {
  if (!Array.isArray(rows)) throw new Error('Rekap kelas tidak tersedia.')
  return rows.reduce((sum, row) => {
    for (const key of ['total_siswa', ...STATUSES]) sum[key] += row[key]
    return sum
  }, { total_siswa: 0, hadir: 0, izin: 0, sakit: 0, alpa: 0, belum_ada_data: 0 })
}

export function principalCoverage(totals) {
  const total = totals?.total_siswa
  const missing = totals?.belum_ada_data
  if (!Number.isSafeInteger(total) || total < 0 || !Number.isSafeInteger(missing) || missing < 0 || missing > total) return null
  return total === 0 ? null : (total - missing) / total * 100
}

export function formatCount(value) {
  return Number.isSafeInteger(value) && value >= 0
    ? new Intl.NumberFormat('id-ID').format(value)
    : '—'
}

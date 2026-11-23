export const REPORT_TYPES = { MID_SEMESTER: 'Rapor Tengah Semester', AKHIR_SEMESTER: 'Rapor Akhir Semester' }
export const PAGE_SIZE = 15

export function parseReportCardsResponse(payload) {
  if (!payload || !Array.isArray(payload.data) || !payload.meta || !Number.isInteger(Number(payload.meta.current_page)) || !Number.isInteger(Number(payload.meta.last_page))) {
    throw new Error('Format data rapor dari server tidak valid.')
  }
  const { current_page, last_page, total } = payload.meta
  const page = Number(current_page), last = Number(last_page)
  if (page < 1 || last < 1 || page > last || !Number.isInteger(page) || !Number.isInteger(last)) throw new Error('Pagination rapor tidak valid.')
  const ids = new Set()
  const records = payload.data.map((item) => {
    if (!item || !Number.isInteger(Number(item.id)) || Number(item.id) < 1 || ids.has(String(item.id)) || !item.academic_year || !item.file || !REPORT_TYPES[item.type]) throw new Error('Data rapor tidak konsisten.')
    ids.add(String(item.id))
    return item
  })
  return { records, page, lastPage: last, total: Number.isInteger(Number(total)) && Number(total) >= 0 ? Number(total) : null }
}
export function academicPeriod(item) {
  const year = item?.academic_year
  return year?.tahun_mulai && year?.tahun_selesai ? `${year.tahun_mulai}/${year.tahun_selesai} · ${year.semester || 'Semester tidak tersedia'}` : 'Periode tidak tersedia'
}
export function readableSize(bytes) {
  if (bytes === undefined || bytes === null || !Number.isFinite(Number(bytes)) || Number(bytes) < 0) return 'Ukuran tidak tersedia'
  return `${(Number(bytes) / 1024).toFixed(1)} KB`
}
export function displayDate(value) {
  if (!value || !Number.isFinite(new Date(value).getTime())) return 'Tanggal tidak tersedia'
  return new Intl.DateTimeFormat('id-ID', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'Asia/Jakarta' }).format(new Date(value))
}
export function availablePeriods(items) {
  const byId = new Map()
  for (const item of items) if (item.academic_year?.id) byId.set(String(item.academic_year.id), { id: String(item.academic_year.id), label: academicPeriod(item) })
  return [...byId.values()]
}

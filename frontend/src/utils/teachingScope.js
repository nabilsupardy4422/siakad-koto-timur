// Shared teaching-context helpers. Frontend filtering is defense-in-depth,
// not a replacement for Laravel authorization.
export const sameId = (a, b) => a != null && b != null && String(a) !== '' && String(a) === String(b)
export const teacherId = user => user?.guru?.id ?? null
export const scheduleOwnerId = schedule => schedule?.guru_id ?? schedule?.guru?.id
export const scheduleYearId = schedule => schedule?.tahun_akademik_id ?? schedule?.tahun_akademik?.id

export function ownTeachingSchedules(items, guruId, yearId) {
  if (guruId == null || yearId == null) return []
  return items.filter(item => item?.id != null &&
    sameId(scheduleOwnerId(item), guruId) &&
    sameId(scheduleYearId(item), yearId))
}

export async function collectPages(fetchPage) {
  const records = new Map()
  for (let page = 1; page <= 10000; page++) {
    const response = await fetchPage(page)
    if (!Array.isArray(response?.data)) throw new Error('Format daftar dari server tidak valid.')
    const last = Number(response?.meta?.last_page ?? 1)
    if (!Number.isInteger(last) || last < 1 || last > 10000) throw new Error('Informasi pagination tidak valid.')
    if (response?.meta?.current_page != null && Number(response.meta.current_page) !== page) {
      throw new Error('Halaman data yang diterima tidak sesuai.')
    }
    response.data.forEach(item => { if (item?.id != null) records.set(String(item.id), item) })
    if (page >= last) return [...records.values()]
  }
  throw new Error('Batas pemuatan data terlampaui.')
}

export async function loadOwnTeachingSchedules(scheduleApi, guruId, yearId) {
  if (guruId == null || String(guruId) === '' || yearId == null || String(yearId) === '') {
    throw new Error('Identitas Guru atau tahun akademik belum tersedia.')
  }
  const records = await collectPages(page => scheduleApi.getAll({
    scope: 'teaching',
    teacher_id: guruId,
    academic_year_id: yearId,
    per_page: 100,
    page,
  }))
  return ownTeachingSchedules(records, guruId, yearId)
}

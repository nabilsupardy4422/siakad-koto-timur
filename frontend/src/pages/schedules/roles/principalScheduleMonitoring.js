// Kontrak baca untuk monitoring Jadwal Kepala Sekolah.
// GET /academic-years tidak memakai pagination; GET /schedules memakainya.
const DAYS = ['SENIN', 'SELASA', 'RABU', 'KAMIS', 'JUMAT', 'SABTU', 'MINGGU']

function positiveId(value) {
  const number = Number(value)
  return Number.isSafeInteger(number) && number > 0 ? number : null
}

function nonnegativeInt(value) {
  if (value === null || value === undefined || String(value).trim() === '') return null
  const number = Number(value)
  return Number.isSafeInteger(number) && number >= 0 ? number : null
}

function activeFlag(value) {
  if (value === true || value === 1 || value === '1') return true
  if (value === false || value === 0 || value === '0') return false
  throw new Error('Status aktif periode akademik tidak valid.')
}

export function parsePrincipalAcademicYears(response, allowConflict = false) {
  if (!Array.isArray(response?.data)) {
    throw new Error('Daftar tahun akademik tidak sesuai kontrak API.')
  }
  const seen = new Set()
  const years = response.data.map(year => {
    const id = positiveId(year?.id)
    const start = nonnegativeInt(year?.tahun_mulai)
    const end = nonnegativeInt(year?.tahun_selesai)
    if (!id || seen.has(id) || start === null || end === null ||
        start < 2000 || end > 2200 || end !== start + 1 ||
        !['GANJIL', 'GENAP'].includes(year?.semester)) {
      throw new Error('Data periode akademik tidak valid atau duplikat.')
    }
    seen.add(id)
    return { ...year, id, tahun_mulai: start, tahun_selesai: end, is_active: activeFlag(year.is_active) }
  })
  if (!allowConflict && years.filter(item => item.is_active).length > 1) {
    throw new Error('Terdapat lebih dari satu periode aktif. Hubungi Tata Usaha.')
  }
  return years.sort((a, b) => b.tahun_mulai - a.tahun_mulai ||
    (a.semester === 'GENAP' ? -1 : 1) - (b.semester === 'GENAP' ? -1 : 1) || b.id - a.id)
}

export function choosePrincipalPeriod(years, currentId) {
  const retained = years.find(year => String(year.id) === String(currentId))
  const active = years.filter(year => year.is_active)
  return retained?.id ?? (active.length === 1 ? active[0].id : null)
}

function normalizeTime(value) {
  if (typeof value !== 'string' || !/^\d\d:\d\d(?::\d\d)?$/.test(value)) return null
  const [h, m, s = '00'] = value.split(':').map(Number)
  if (h > 23 || m > 59 || s > 59) return null
  return h * 60 + m + s / 60
}

export function parsePrincipalSchedulePage(response, yearId, requestedPage) {
  const meta = response?.meta
  const rows = response?.data
  const page = positiveId(meta?.current_page)
  const last = positiveId(meta?.last_page)
  const size = positiveId(meta?.per_page)
  const total = nonnegativeInt(meta?.total)
  if (!Array.isArray(rows) || !page || !last || !size || total === null ||
      page !== requestedPage || page > last || rows.length > size ||
      (page < last && rows.length !== size) ||
      (total === 0 && (last !== 1 || rows.length !== 0)) ||
      (total > 0 && last !== Math.ceil(total / size))) {
    throw new Error('Pagination jadwal dari server tidak valid.')
  }
  const seen = new Set()
  const expectedYear = positiveId(yearId)
  if (!expectedYear) throw new Error('Periode jadwal tidak valid.')
  const schedules = rows.map(item => {
    const day = typeof item?.hari === 'string' ? item.hari.toUpperCase().trim() : ''
    const id = positiveId(item?.id)
    const periodId = positiveId(item?.tahun_akademik_id)
    const classId = positiveId(item?.kelas_id)
    const teacherId = positiveId(item?.guru_id)
    const subjectId = positiveId(item?.mapel_id)
    const start = normalizeTime(item?.jam_mulai)
    const end = normalizeTime(item?.jam_selesai)
    if (!id || seen.has(id) || periodId !== expectedYear ||
        !classId || !teacherId || !subjectId ||
        !DAYS.includes(day) || start === null || end === null || end <= start ||
        (item?.kelas && positiveId(item.kelas.id) !== classId) ||
        (item?.guru && positiveId(item.guru.id) !== teacherId) ||
        (item?.mapel && positiveId(item.mapel.id) !== subjectId) ||
        (item?.tahun_akademik && positiveId(item.tahun_akademik.id) !== expectedYear)) {
      throw new Error('Data jadwal memiliki identitas, waktu, atau periode tidak konsisten.')
    }
    seen.add(id)
    return { ...item, hari: day }
  })
  return { rows: schedules, meta: { page, last, size, total } }
}

export function combinePrincipalSchedulePages(pages) {
  if (pages.length === 0) throw new Error('Hasil pagination jadwal belum lengkap.')
  const first = pages[0].meta
  const seen = new Set()
  const rows = []
  if (pages.length !== first.last) throw new Error('Tidak semua halaman jadwal berhasil dimuat.')
  pages.forEach((page, index) => {
    if (page.meta.page !== index + 1 || page.meta.last !== first.last ||
        page.meta.size !== first.size || page.meta.total !== first.total) {
      throw new Error('Metadata halaman jadwal berubah saat pengambilan data.')
    }
    page.rows.forEach(row => {
      if (seen.has(row.id)) throw new Error('Terdapat jadwal duplikat dari server.')
      seen.add(row.id)
      rows.push(row)
    })
  })
  if (rows.length !== first.total) throw new Error('Jumlah jadwal tidak sesuai total dari server.')
  return rows
}

export function principalScheduleClassLabel(item) {
  const tingkat = item.kelas?.tingkat
  const nama = item.kelas?.nama || `Kelas #${item.kelas_id}`
  const startsWithLevel = new RegExp(`^(?:${tingkat}|XII|XI|X)\\s`, 'i').test(nama)
  return tingkat && !startsWithLevel ? `Tingkat ${tingkat} · ${nama}` : nama
}

export function principalScheduleOptions(schedules, idOf, labelOf) {
  const options = new Map()
  for (const item of schedules) {
    const id = positiveId(idOf(item))
    if (id && !options.has(String(id))) options.set(String(id), { value: String(id), label: String(labelOf(item)) })
  }
  return [...options.values()].sort((a, b) => a.label.localeCompare(b.label, 'id'))
}

export function filterPrincipalSchedules(schedules, filters) {
  return schedules.filter(item =>
    (!filters.class_id || String(item.kelas_id) === filters.class_id) &&
    (!filters.teacher_id || String(item.guru_id) === filters.teacher_id) &&
    (!filters.subject_id || String(item.mapel_id) === filters.subject_id) &&
    (!filters.day || item.hari === filters.day))
}

export function principalScheduleSummary(rows) {
  return {
    schedules: rows.length,
    classes: new Set(rows.map(x => x.kelas_id)).size,
    teachers: new Set(rows.map(x => x.guru_id)).size,
    subjects: new Set(rows.map(x => x.mapel_id)).size,
  }
}

export function sortPrincipalWeekly(rows) {
  return [...rows].sort((a, b) =>
    DAYS.indexOf(a.hari) - DAYS.indexOf(b.hari) ||
    normalizeTime(a.jam_mulai) - normalizeTime(b.jam_mulai) ||
    a.kelas_id - b.kelas_id || a.id - b.id)
}


// Label singkat untuk kartu jadwal yang menampilkan kelas bernama sama di tingkat berbeda.
// Salinan presentasi saja: ID, scope, dan objek asli tidak berubah.
export function principalTimetableDisplaySchedules(rows) {
  const roman = { '10': 'X', '11': 'XI', '12': 'XII', X: 'X', XI: 'XI', XII: 'XII' }
  return rows.map(item => {
    const name = item.kelas?.nama || item.kelas?.nama_kelas
    const level = roman[String(item.kelas?.tingkat ?? '').toUpperCase()]
    if (!level || !name || /^(?:(?:XII|XI|X|10|11|12)\b|(?:kelas|tingkat)\s+(?:XII|XI|X|10|11|12)\b)/i.test(name)) {
      return item
    }
    return { ...item, kelas: { ...item.kelas, nama: `${level} ${name}` } }
  })
}

// Controls for the existing scrollable grid. Work with mouse emulators and touch phones;
// do not move the entire page or change the timetable's selected week/date.
export function scrollPrincipalTimetable(root, direction) {
  if (direction !== -1 && direction !== 1) return false
  const region = root?.querySelector?.('.timetable__grid-scroll, .timetable__month-view')
  if (!region || !Number.isFinite(region.clientWidth) ||
      !Number.isFinite(region.scrollWidth) || region.scrollWidth <= region.clientWidth + 1) {
    return false
  }
  const delta = Math.max(180, Math.round(region.clientWidth * 0.8)) * direction
  if (typeof region.scrollBy === 'function') {
    region.scrollBy({ left: delta, behavior: 'auto' })
  } else {
    region.scrollLeft += delta
  }
  return true
}

export { DAYS as PRINCIPAL_SCHEDULE_DAYS }

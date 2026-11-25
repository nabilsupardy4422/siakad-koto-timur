// SIAKAD Kepala Sekolah Laporan V1.10.1 — kontrak ekspor lengkap.
// File ini WAJIB disalin utuh ke lokasi yang disebutkan dalam panduan.
// Kontrak laporan Kepala Sekolah. Semua keputusan izin akhir tetap di Laravel.
export const PRINCIPAL_REPORT_TYPES = Object.freeze([
  { id: 'students', label: 'Data Siswa', caption: 'Identitas dan keanggotaan kelas', mark: '01' },
  { id: 'teachers', label: 'Data Guru', caption: 'Nama dan NIP pada dokumen', mark: '02' },
  { id: 'classes', label: 'Data Kelas', caption: 'Daftar kelas per periode', mark: '03' },
  { id: 'schedules', label: 'Jadwal Pelajaran', caption: 'Hari, jam, kelas dan guru', mark: '04' },
  { id: 'attendance', label: 'Presensi', caption: 'Catatan siswa per pertemuan', mark: '05' },
  { id: 'grades', label: 'Nilai', caption: 'Skor komponen yang tersimpan', mark: '06' },
  { id: 'semester-recap', label: 'Rekap Semester', caption: 'Rekap catatan, bukan rapor', mark: '07' },
])

export const PRINCIPAL_REPORT_FIELDS = Object.freeze({
  students: ['academic_year_id', 'class_id', 'search'],
  teachers: ['search'],
  classes: ['academic_year_id'],
  schedules: ['academic_year_id', 'class_id', 'subject_id', 'teacher_id'],
  attendance: ['academic_year_id', 'class_id', 'subject_id', 'date_from', 'date_to'],
  grades: ['academic_year_id', 'class_id', 'subject_id'],
  'semester-recap': ['academic_year_id', 'class_id'],
})

export const REPORT_FILE_LABEL = Object.freeze({
  students: 'data-siswa', teachers: 'data-guru', classes: 'data-kelas',
  schedules: 'jadwal', attendance: 'presensi', grades: 'nilai',
  'semester-recap': 'rekap-semester',
})

const isObject = v => v !== null && typeof v === 'object' && !Array.isArray(v)
export const positiveId = v => {
  const n = Number(v)
  return /^\d+$/.test(String(v)) && Number.isSafeInteger(n) && n > 0 ? n : null
}

export function parseYears(response) {
  if (!isObject(response) || !Array.isArray(response.data)) {
    throw new Error('Respons tahun akademik tidak sesuai kontrak API.')
  }
  const ids = new Set()
  const years = response.data.map(year => {
    if (!isObject(year) || !positiveId(year.id) || ids.has(Number(year.id)) ||
      !Number.isInteger(Number(year.tahun_mulai)) || !Number.isInteger(Number(year.tahun_selesai)) ||
      !['GANJIL', 'GENAP'].includes(String(year.semester).toUpperCase()) ||
      ![true, false, 0, 1].includes(year.is_active)) {
      throw new Error('Data tahun akademik tidak valid atau berulang.')
    }
    ids.add(Number(year.id))
    return { ...year, id: Number(year.id), semester: String(year.semester).toUpperCase(), is_active: Boolean(year.is_active) }
  })
  if (years.filter(y => y.is_active).length > 1) {
    throw new Error('Ada lebih dari satu periode aktif.')
  }
  return years
}

export function parseCatalogPage(response, expectedPage) {
  if (!isObject(response) || !Array.isArray(response.data) || !isObject(response.meta)) {
    throw new Error('Pagination daftar filter tidak tersedia.')
  }
  const { current_page, last_page, per_page, total } = response.meta
  const nums = [current_page, last_page, per_page, total].map(Number)
  if (!nums.every(Number.isSafeInteger) || nums[0] !== expectedPage || nums[0] < 1 ||
      nums[1] < 1 || nums[0] > nums[1] || nums[2] < 1 || nums[3] < 0 ||
      nums[1] !== Math.max(1, Math.ceil(nums[3] / nums[2])) || response.data.length > nums[2] ||
      (nums[3] === 0 && response.data.length !== 0) ||
      (nums[3] > 0 && response.data.length === 0)) {
    throw new Error('Metadata pagination filter tidak konsisten.')
  }
  return { items: response.data, last: nums[1], total: nums[3] }
}

export async function loadEntireCatalog(fetchPage) {
  const list = []
  const seen = new Set()
  let last = 1
  let total = 0
  for (let page = 1; page <= last; page++) {
    if (page > 200) throw new Error('Daftar filter terlalu panjang untuk dimuat dengan aman.')
    const parsed = parseCatalogPage(await fetchPage(page), page)
    if (page === 1) { last = parsed.last; total = parsed.total }
    if (parsed.last !== last || parsed.total !== total) {
      throw new Error('Daftar filter berubah saat dipaginasi. Muat ulang data.')
    }
    for (const row of parsed.items) {
      const id = positiveId(row?.id)
      if (!id || seen.has(id)) throw new Error('Identitas filter tidak valid atau duplikat.')
      seen.add(id)
      list.push(row)
    }
  }
  if (list.length !== total) throw new Error('Jumlah entri filter tidak lengkap.')
  return list
}

function validISODate(value) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return false
  const timestamp = Date.parse(`${value}T00:00:00.000Z`)
  return Number.isFinite(timestamp) && new Date(timestamp).toISOString().slice(0, 10) === value
}

export function makePrincipalExportParams(type, yearId, filters = {}, format = 'pdf', availableYears = []) {
  const fields = PRINCIPAL_REPORT_FIELDS[type]
  if (!fields || !['pdf', 'excel'].includes(format)) throw new Error('Jenis atau format laporan tidak valid.')
  const params = { scope: 'school', format }
  if (fields.includes('academic_year_id')) {
    const id = positiveId(yearId)
    if (!id || !availableYears.some(y => y.id === id)) {
      throw new Error('Pilih tahun akademik/semester yang tersedia.')
    }
    params.academic_year_id = id
  }
  for (const field of fields) {
    if (field === 'academic_year_id') continue
    const val = filters[field]
    if (val === '' || val === undefined || val === null) continue
    if (['class_id', 'subject_id', 'teacher_id'].includes(field)) {
      const id = positiveId(val)
      if (!id) throw new Error(`Filter ${field} tidak valid.`)
      params[field] = id
    } else if (field === 'search') {
      if (typeof val !== 'string' || val.trim().length > 100) throw new Error('Pencarian maksimal 100 karakter.')
      if (val.trim()) params.search = val.trim()
    } else if (field === 'date_from' || field === 'date_to') {
      if (!validISODate(val)) throw new Error('Tanggal filter tidak valid.')
      params[field] = val
    }
  }
  if (params.date_to && params.date_from && params.date_to < params.date_from) {
    throw new Error('Tanggal selesai tidak boleh mendahului tanggal mulai.')
  }
  return params
}

export function formatReportPeriod(year) {
  if (!year) return 'Belum ada periode'
  return `${year.tahun_mulai}/${year.tahun_selesai} · ${year.semester}${year.is_active ? ' (Aktif)' : ''}`
}

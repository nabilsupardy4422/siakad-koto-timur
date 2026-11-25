/**
 * Kontrak data khusus dashboard Kepala Sekolah.
 * Tidak membuat angka default palsu: null berarti data tidak dikirim server,
 * sedangkan 0 berarti nol yang benar-benar diterima dari server.
 */

function nonNegativeInteger(value) {
  if (typeof value === 'number') {
    return Number.isSafeInteger(value) && value >= 0 ? value : null
  }

  if (typeof value === 'string' && /^\d+$/.test(value.trim())) {
    const parsed = Number(value.trim())
    return Number.isSafeInteger(parsed) ? parsed : null
  }

  return null
}

function academicYearLabel(value) {
  if (!value || typeof value !== 'object') return null

  const start = nonNegativeInteger(value.tahun_mulai)
  const end = nonNegativeInteger(value.tahun_selesai)

  if (start === null || end === null || start < 1900 || end < start) {
    return null
  }

  return `${start}/${end}`
}

function semesterLabel(value) {
  const semester = String(value ?? '').trim().toLowerCase()
  if (semester === 'ganjil' || semester === '1') return 'Ganjil'
  if (semester === 'genap' || semester === '2') return 'Genap'
  return null
}

function normalizedYear(value) {
  if (!value || typeof value !== 'object') return null

  return {
    id: nonNegativeInteger(value.id),
    label: academicYearLabel(value),
    semester: semesterLabel(value.semester),
    isActive: value.is_active === true || value.is_active === 1,
  }
}

function normalizeClasses(value) {
  if (!Array.isArray(value)) return []

  const seen = new Set()
  const rows = []

  for (const item of value) {
    if (!item || typeof item !== 'object') continue
    const id = nonNegativeInteger(item.class_id)
    const count = nonNegativeInteger(item.student_count)
    const rawName = typeof item.class_name === 'string' ? item.class_name.trim() : ''
    const levels = { 10: 'X', 11: 'XI', 12: 'XII' }
    const level = levels[item.tingkat] ?? null
    // Backend includes 'tingkat' separately; show it unless the name already has a level.
    const name = level && !/^(?:XII|XI|X|12|11|10)(?:\s|[-./]|$)/i.test(rawName)
      ? `${level} ${rawName}`
      : rawName

    if (id === null || id === 0 || count === null || !name || seen.has(id)) continue
    seen.add(id)
    rows.push({ id, name, count })
  }

  return rows.sort((a, b) => b.count - a.count || a.name.localeCompare(b.name, 'id'))
}

export function normalizePrincipalDashboard(input) {
  const data = input?.data ?? input

  if (!data || typeof data !== 'object' || data.role !== 'KEPALA_SEKOLAH') {
    throw new Error('Respons dashboard Kepala Sekolah tidak sesuai dengan hak akses akun.')
  }

  const summary = data.summary && typeof data.summary === 'object' ? data.summary : {}
  const year = normalizedYear(summary.active_academic_year)
  const years = Array.isArray(data.recent?.academic_years)
    ? data.recent.academic_years.map(normalizedYear).filter((item) => item?.label).slice(0, 5)
    : []

  return {
    metrics: [
      { key: 'guru', label: 'Guru', value: nonNegativeInteger(summary.total_guru), note: 'Seluruh profil guru tercatat' },
      { key: 'siswa', label: 'Siswa', value: nonNegativeInteger(summary.total_siswa), note: 'Seluruh profil siswa tercatat' },
      { key: 'kelas', label: 'Kelas', value: nonNegativeInteger(summary.total_kelas), note: year?.label ? 'Pada periode akademik aktif' : 'Dari seluruh periode tercatat' },
      { key: 'jadwal', label: 'Jadwal', value: nonNegativeInteger(summary.total_jadwal), note: year?.label ? 'Pada periode akademik aktif' : 'Dari seluruh periode tercatat' },
    ],
    year,
    years,
    classes: normalizeClasses(data.charts?.students_by_class),
  }
}

export function formatPrincipalNumber(value) {
  return value === null ? '—' : new Intl.NumberFormat('id-ID').format(value)
}

export function classBarWidth(count, maxCount) {
  if (!Number.isFinite(count) || count <= 0 || !Number.isFinite(maxCount) || maxCount <= 0) return 0
  return Math.min(100, (count / maxCount) * 100)
}

/**
 * Presentation-only comparison. Profiles and membership rows have distinct
 * units; the difference never establishes which students lack a class.
 * The caller supplies precisely the class rows currently rendered.
 */
export function principalEnrollmentScope(classes, studentMetric) {
  if (!Array.isArray(classes) || classes.length === 0) return null

  let memberships = 0
  for (const item of classes) {
    if (!Number.isSafeInteger(item?.count) || item.count < 0) return null
    const next = memberships + item.count
    if (!Number.isSafeInteger(next)) return null
    memberships = next
  }

  const profileValue = studentMetric?.value
  const registeredProfiles = Number.isSafeInteger(profileValue) && profileValue >= 0
    ? profileValue
    : null

  return { registeredProfiles, memberships, displayedClasses: classes.length }
}

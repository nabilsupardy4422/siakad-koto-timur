import { isISODate } from './principalDailyRecap.js'

const FIELDS = ['total', 'hadir', 'izin', 'sakit', 'alpa']
const count = value => Number.isSafeInteger(value) && value >= 0 ? value : null
const identity = value => Number.isSafeInteger(value) && value > 0 ? value : null
const label = value => typeof value === 'string' && value.trim() ? value.trim() : null

export function periodParams({ yearId, scope, from, to }) {
  if (!identity(Number(yearId)) || !['SEMESTER', 'YEAR'].includes(scope)) {
    throw new Error('Periode monitoring tidak valid.')
  }
  if (scope === 'YEAR') return { academic_year_id: yearId, scope }
  if (!isISODate(from) || !isISODate(to) || from > to) {
    throw new Error('Rentang tanggal tidak valid atau tanggal awal melebihi tanggal akhir.')
  }
  return { academic_year_id: yearId, scope, date_from: from, date_to: to }
}

function parseCounts(row) {
  const result = Object.fromEntries(FIELDS.map(key => [key, count(row?.[key])]))
  if (Object.values(result).some(value => value === null)) throw new Error('Jumlah catatan presensi tidak valid.')
  if (FIELDS.slice(1).reduce((sum, field) => sum + result[field], 0) !== result.total) {
    throw new Error('Jumlah status tidak sesuai total catatan pertemuan.')
  }
  return result
}

function principalClassLabel(row) {
  const level = { 10: 'X', 11: 'XI', 12: 'XII' }[Number(row.grade_level)]
  const name = label(row.class_name)
  if (!level || !name) throw new Error('Identitas kelas tidak lengkap.')
  return new RegExp(`^(${level}|${Number(row.grade_level)})(\\s|[-–])`, 'i').test(name) ? name : `${level} ${name}`
}

export function normalizePrincipalPeriodInsights(payload, request) {
  if (!payload?.data || !Array.isArray(payload.data.classes) || !payload.meta) {
    throw new Error('Kontrak respons analitik presensi tidak valid.')
  }
  const expected = periodParams(request)
  const meta = payload.meta
  if (meta.unit !== 'stored_student_meeting_records' || meta.scope !== expected.scope ||
    identity(Number(meta.academic_year_id)) !== Number(expected.academic_year_id) ||
    (expected.scope === 'SEMESTER' && (meta.date_from !== expected.date_from || meta.date_to !== expected.date_to))) {
    throw new Error('Konteks analitik tidak sesuai permintaan.')
  }
  if (!Array.isArray(meta.periods) || meta.periods.length < 1 || meta.periods.length > 2) {
    throw new Error('Daftar semester pada respons tidak valid.')
  }
  const ids = new Set()
  const semesters = new Set()
  for (const period of meta.periods) {
    if (!identity(period?.id) || !['GANJIL', 'GENAP'].includes(period?.semester) || ids.has(period.id) || semesters.has(period.semester)) {
      throw new Error('Semester pada respons tidak konsisten.')
    }
    ids.add(period.id)
    semesters.add(period.semester)
  }
  if (expected.scope === 'SEMESTER' && (meta.periods.length !== 1 || !ids.has(Number(expected.academic_year_id)))) {
    throw new Error('Respons semester tidak sesuai tahun akademik.')
  }
  const seenClasses = new Set()
  const classes = payload.data.classes.map(row => {
    const yearId = identity(row?.academic_year_id)
    const classId = identity(row?.class_id)
    if (!yearId || !classId || !ids.has(yearId) || !semesters.has(row?.semester)) {
      throw new Error('Periode atau kelas dalam catatan tidak sesuai.')
    }
    const classKey = `${yearId}:${classId}`
    if (seenClasses.has(classKey)) throw new Error('Kelas duplikat dalam analitik.')
    seenClasses.add(classKey)
    if (!Array.isArray(row.subjects) || row.subjects.length === 0) {
      throw new Error('Kelas tanpa catatan mapel tidak boleh muncul sebagai catatan.')
    }
    const subjectsSeen = new Set()
    const subjects = row.subjects.map(subject => {
      const subjectId = identity(subject?.subject_id)
      const name = label(subject?.subject_name)
      const recordedStudents = count(subject?.recorded_students)
      if (!subjectId || !name || recordedStudents === null || subjectsSeen.has(subjectId)) {
        throw new Error('Identitas mapel atau jumlah siswa tercatat tidak valid.')
      }
      subjectsSeen.add(subjectId)
      const totals = parseCounts(subject)
      if (recordedStudents > totals.total) throw new Error('Jumlah siswa unik melebihi catatan.')
      return { id: subjectId, name, recordedStudents, ...totals }
    })
    const totals = parseCounts(row.totals)
    for (const field of FIELDS) {
      if (subjects.reduce((sum, subject) => sum + subject[field], 0) !== totals[field]) {
        throw new Error('Total kelas tidak sama dengan jumlah catatan mapel.')
      }
    }
    return { id: classId, key: classKey, yearId, semester: row.semester,
      label: principalClassLabel(row), subjects, totals }
  })
  const totals = parseCounts(payload.data.totals)
  for (const field of FIELDS) {
    if (classes.reduce((sum, item) => sum + item.totals[field], 0) !== totals[field]) {
      throw new Error('Total sekolah tidak sama dengan jumlah kelas.')
    }
  }
  return { classes, totals, periods: meta.periods, year: label(meta.year) || '—' }
}

export function recordedPresenceRate(totals) {
  const parsed = parseCounts(totals)
  return parsed.total > 0 ? parsed.hadir / parsed.total * 100 : null
}

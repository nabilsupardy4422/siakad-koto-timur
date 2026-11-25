import { isISODate } from './principalDailyRecap.js'

const int = value => Number.isSafeInteger(value) && value >= 0 ? value : null
const id = value => Number.isSafeInteger(value) && value > 0 ? value : null
const label = value => typeof value === 'string' && value.trim() ? value.trim() : null
const STATES = new Set(['complete', 'partial', 'without_records', 'not_finished', 'without_students'])
const TIMES = new Set(['upcoming', 'ongoing', 'finished'])

export function scheduleCoverageParams({ yearId, date, classId = '' }) {
  const year = Number(yearId)
  if (!id(year) || !isISODate(date)) throw new Error('Tahun akademik atau tanggal monitoring tidak valid.')
  const params = { academic_year_id: year, date }
  if (classId !== '' && classId !== null && classId !== undefined) {
    const selected = Number(classId)
    if (!id(selected)) throw new Error('Kelas monitoring tidak valid.')
    params.class_id = selected
  }
  return params
}

function className(row) {
  const name = label(row.class_name)
  const level = { 10: 'X', 11: 'XI', 12: 'XII' }[row.grade_level]
  if (!name || !level) throw new Error('Identitas kelas pada jadwal tidak valid.')
  return new RegExp(`^(${level}|${row.grade_level})(\\s|[-–])`, 'i').test(name) ? name : `${level} ${name}`
}

export function normalizePrincipalScheduleCoverage(payload, request) {
  const params = scheduleCoverageParams(request)
  const summary = payload?.data?.summary
  const schedules = payload?.data?.schedules
  const meta = payload?.meta
  if (!summary || !Array.isArray(schedules) || !meta) throw new Error('Respons monitoring jadwal tidak sesuai kontrak API.')
  if (meta.unit !== 'weekly_schedule_with_saved_student_records' || meta.is_actual_lesson_confirmation !== false ||
    meta.academic_year_id !== params.academic_year_id || meta.date !== params.date ||
    meta.class_id !== (params.class_id ?? null)) throw new Error('Konteks monitoring jadwal tidak sesuai filter.')
  if (!label(meta.weekday)) throw new Error('Hari monitoring tidak diketahui.')

  const keys = ['scheduled', 'with_students', 'evaluated', 'with_records', 'complete', 'partial', 'without_records', 'not_finished', 'without_students']
  const counts = Object.fromEntries(keys.map(key => [key, int(summary[key])]))
  if (Object.values(counts).some(value => value === null) ||
    counts.scheduled !== schedules.length ||
    counts.scheduled !== counts.with_students + counts.without_students ||
    counts.with_students !== counts.evaluated + counts.not_finished ||
    counts.evaluated !== counts.with_records + counts.without_records ||
    counts.with_records !== counts.complete + counts.partial) {
    throw new Error('Ringkasan jadwal dan status pencatatan tidak konsisten.')
  }

  const seen = new Set()
  const rows = schedules.map(row => {
    if (!id(row?.schedule_id) || !id(row.class_id) || seen.has(row.schedule_id) ||
      (params.class_id && params.class_id !== row.class_id) ||
      !label(row.subject_name) || !label(row.teacher_name) ||
      !STATES.has(row.state) || !TIMES.has(row.time_state) ||
      !/^\d{2}:\d{2}$/.test(row.start_time) || !/^\d{2}:\d{2}$/.test(row.end_time)) {
      throw new Error('Identitas jadwal monitoring tidak valid atau duplikat.')
    }
    const students = int(row.students)
    const recorded = int(row.recorded_students)
    if (students === null || recorded === null || recorded > students) throw new Error('Jumlah presensi jadwal tidak valid.')
    const correct = row.state === 'without_students' ? students === 0 && recorded === 0
      : row.state === 'not_finished' ? students > 0 && row.time_state !== 'finished'
        : row.time_state === 'finished' && students > 0 && (
          row.state === 'complete' ? recorded === students
            : row.state === 'partial' ? recorded > 0 && recorded < students
              : recorded === 0)
    if (!correct) throw new Error('Status jadwal bertentangan dengan jumlah presensi.')
    seen.add(row.schedule_id)
    return {
      id: row.schedule_id, classId: row.class_id, classLabel: className(row),
      subject: row.subject_name.trim(), teacher: row.teacher_name.trim(),
      time: `${row.start_time}–${row.end_time}`, students, recorded,
      state: row.state, timeState: row.time_state,
    }
  })
  const statusCount = key => rows.filter(row => row.state === key).length
  for (const [key, stateKey] of [['complete', 'complete'], ['partial', 'partial'], ['without_records', 'without_records'], ['not_finished', 'not_finished'], ['without_students', 'without_students']]) {
    if (counts[key] !== statusCount(stateKey)) throw new Error('Status rincian jadwal tidak sesuai ringkasan.')
  }
  return { summary: counts, rows, weekday: meta.weekday, date: meta.date }
}

export function recordedScheduleRate(summary) {
  return summary.evaluated > 0 ? 100 * summary.with_records / summary.evaluated : null
}

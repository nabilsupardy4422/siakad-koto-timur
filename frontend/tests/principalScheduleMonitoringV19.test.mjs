import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  parsePrincipalAcademicYears, choosePrincipalPeriod,
  parsePrincipalSchedulePage, combinePrincipalSchedulePages,
  principalScheduleClassLabel, principalScheduleOptions,
  filterPrincipalSchedules, principalScheduleSummary, sortPrincipalWeekly,
  principalTimetableDisplaySchedules, scrollPrincipalTimetable,
} from '../src/pages/schedules/roles/principalScheduleMonitoring.js'

const year = (id, semester, active = false) => ({ id, tahun_mulai: 2026, tahun_selesai: 2027, semester, is_active: active })
const schedule = (id, overrides = {}) => ({
  id, tahun_akademik_id: 1, kelas_id: 3, mapel_id: 8, guru_id: 12,
  hari: 'SENIN', jam_mulai: '07:30:00', jam_selesai: '09:00:00',
  kelas: { id: 3, nama: 'IPA 1', tingkat: 10 },
  mapel: { id: 8, nama: 'Bahasa Indonesia' },
  guru: { id: 12, nama_lengkap: 'Guru 12' },
  tahun_akademik: { id: 1 },
  ...overrides,
})
function page(rows, current = 1, last = 1, total = rows.length, size = 100) {
  return { data: rows, meta: { current_page: current, last_page: last, per_page: size, total } }
}

test('years API has complete unpaginated array and semester is selected as one period', () => {
  const years = parsePrincipalAcademicYears({ data: [year(2, 'GENAP', 0), year(1, 'GANJIL', 1)] })
  assert.equal(years.length, 2)
  assert.equal(choosePrincipalPeriod(years, ''), 1)
  assert.equal(choosePrincipalPeriod(years, '2'), 2)
  assert.equal(years[0].semester, 'GENAP')
})

test('years reject invalid/ambiguous active flag, duplicates and wrong envelope', () => {
  assert.throws(() => parsePrincipalAcademicYears({ data: {} }), /kontrak/)
  assert.throws(() => parsePrincipalAcademicYears({ data: [year(1, 'GANJIL', 'true')] }), /Status aktif/)
  assert.throws(() => parsePrincipalAcademicYears({ data: [year(1, 'GANJIL'), year(1, 'GENAP')] }), /duplikat/)
  assert.throws(() => parsePrincipalAcademicYears({ data: [year(1, 'GANJIL', true), year(2, 'GENAP', true)] }), /lebih dari satu/)
})

test('schedule page requires real pagination and correct academic-year IDs', () => {
  const parsed = parsePrincipalSchedulePage(page([schedule(7)]), 1, 1)
  assert.equal(parsed.meta.total, 1)
  assert.throws(() => parsePrincipalSchedulePage({ data: [], meta: {} }, 1, 1), /Pagination/)
  assert.throws(() => parsePrincipalSchedulePage({ data: [], meta: { current_page: 1, last_page: 1, per_page: 20 } }, 1, 1), /Pagination/)
  assert.throws(() => parsePrincipalSchedulePage(page([schedule(7)], 2, 2, 2, 1), 1, 1), /Pagination/)
  assert.throws(() => parsePrincipalSchedulePage(page([schedule(7, { tahun_akademik_id: 3 })]), 1, 1), /periode/)
})

test('schedule rows normalize mixed-case Indonesian weekday from legacy rows', () => {
  const result = parsePrincipalSchedulePage(page([schedule(5, { hari: 'Senin' })]), 1, 1)
  assert.equal(result.rows[0].hari, 'SENIN')
})

test('schedule rows reject related class or teacher mismatch and impossible time', () => {
  assert.throws(() => parsePrincipalSchedulePage(page([schedule(3, { kelas_id: 4 })]), 1, 1), /tidak konsisten/)
  assert.throws(() => parsePrincipalSchedulePage(page([schedule(3, { guru_id: 9 })]), 1, 1), /tidak konsisten/)
  assert.throws(() => parsePrincipalSchedulePage(page([schedule(3, { jam_mulai: '17:00', jam_selesai: '16:00' })]), 1, 1), /tidak konsisten/)
  assert.throws(() => parsePrincipalSchedulePage(page([schedule(3, { hari: 'MONDAY' })]), 1, 1), /tidak konsisten/)
})

test('all pages counted; no truncation, page metadata inconsistency or duplicate IDs', () => {
  const a = parsePrincipalSchedulePage(page([schedule(10)], 1, 2, 2, 1), 1, 1)
  const b = parsePrincipalSchedulePage(page([schedule(11)], 2, 2, 2, 1), 1, 2)
  assert.equal(combinePrincipalSchedulePages([a, b]).length, 2)
  assert.throws(() => combinePrincipalSchedulePages([a]), /berhasil dimuat/)
  assert.throws(() => combinePrincipalSchedulePages([a, parsePrincipalSchedulePage(page([schedule(10)], 2, 2, 2, 1), 1, 2)]), /duplikat/)
  assert.throws(() => combinePrincipalSchedulePages([a, { ...b, meta: { ...b.meta, total: 3 } }]), /berubah/)
})

test('empty schedule is genuine zero, not missing data', () => {
  const p = parsePrincipalSchedulePage(page([], 1, 1, 0, 100), 1, 1)
  assert.deepEqual(combinePrincipalSchedulePages([p]), [])
  assert.deepEqual(principalScheduleSummary([]), { schedules: 0, classes: 0, teachers: 0, subjects: 0 })
})

test('filters use IDs and preserve two distinct classes with identical names', () => {
  const rows = [schedule(1), schedule(2, {
    kelas_id: 5, kelas: { id: 5, nama: 'IPA 1', tingkat: 12 },
    hari: 'RABU', jam_mulai: '10:00:00', jam_selesai: '11:00:00',
  })]
  assert.equal(principalScheduleSummary(rows).classes, 2)
  assert.deepEqual(principalScheduleOptions(rows, x => x.kelas_id, principalScheduleClassLabel).map(x => x.label),
    ['Tingkat 10 · IPA 1', 'Tingkat 12 · IPA 1'])
  assert.equal(filterPrincipalSchedules(rows, { class_id: '5', teacher_id: '', subject_id: '', day: '' }).length, 1)
  assert.equal(filterPrincipalSchedules(rows, { class_id: '', teacher_id: '', subject_id: '', day: 'SELASA' }).length, 0)
  assert.equal(sortPrincipalWeekly(rows)[0].hari, 'SENIN')
})

test('principal-only page uses existing read-only GET API, not new backend or teacher status', () => {
  const pageSource = readFileSync(new URL('../src/pages/schedules/roles/PrincipalSchedule.jsx', import.meta.url), 'utf8')
  const controller = readFileSync(new URL('../src/pages/schedules/SchedulesPage.jsx', import.meta.url), 'utf8')
  assert.match(pageSource, /academicYearApi\.getAll\(\)/)
  assert.match(pageSource, /scheduleApi\.getAll\(/)
  assert.doesNotMatch(pageSource, /(?:scheduleApi|academicYearApi)\.(?:create|update|delete|importExcel)/)
  assert.match(pageSource, /<InteractiveTimetable role="KEPALA_SEKOLAH"/)
  assert.match(pageSource, /<HistoricalScheduleList/)
  assert.match(controller, /case 'KEPALA_SEKOLAH'/)
})


test('mobile: horizontal controls scroll the actual week grid in both directions', () => {
  const calls = []
  const grid = { clientWidth: 300, scrollWidth: 850, scrollBy: options => calls.push(options) }
  const root = { querySelector: selector => {
    assert.match(selector, /timetable__grid-scroll/)
    return grid
  } }
  assert.equal(scrollPrincipalTimetable(root, 1), true)
  assert.equal(scrollPrincipalTimetable(root, -1), true)
  assert.deepEqual(calls.map(x => x.left), [240, -240])
})

test('mobile: no fake scroll for empty/fitting grids or invalid direction', () => {
  const grid = { clientWidth: 500, scrollWidth: 500, scrollLeft: 0 }
  const root = { querySelector: () => grid }
  assert.equal(scrollPrincipalTimetable(root, 1), false)
  assert.equal(scrollPrincipalTimetable(root, 0), false)
  assert.equal(scrollPrincipalTimetable(null, 1), false)
  grid.scrollWidth = 850
  assert.equal(scrollPrincipalTimetable(root, 1), true)
  assert.equal(grid.scrollLeft, 400)
})

test('mobile: week cards distinguish class levels without changing source schedule', () => {
  const a = schedule(1)
  const b = schedule(2, { kelas_id: 4, kelas: { id: 4, nama: 'IPA 1', tingkat: 12 } })
  const c = schedule(3, { kelas_id: 5, kelas: { id: 5, nama: 'XI IPA 1', tingkat: 11 } })
  const rows = [a, b, c]
  const result = principalTimetableDisplaySchedules(rows)
  assert.deepEqual(result.map(x => x.kelas.nama), ['X IPA 1', 'XII IPA 1', 'XI IPA 1'])
  assert.equal(a.kelas.nama, 'IPA 1')
  assert.equal(result[0].kelas_id, a.kelas_id)
  assert.equal(result[1].id, b.id)
})

test('mobile: navigation stays in principal page without changing shared timetable or routing', () => {
  const source = readFileSync(new URL('../src/pages/schedules/roles/PrincipalSchedule.jsx', import.meta.url), 'utf8')
  const css = readFileSync(new URL('../src/pages/schedules/styles/principal-schedule-mobile-scroll.css', import.meta.url), 'utf8')
  assert.match(source, /ref=\{timetableAreaRef\}/)
  assert.match(source, /<InteractiveTimetable role="KEPALA_SEKOLAH" schedules=\{timetableSchedules\}/)
  assert.match(source, /aria-label="Geser tabel jadwal ke kanan"/)
  assert.match(css, /touch-action:\s*pan-x pan-y/)
  assert.match(css, /overflow-x:\s*auto/)
  assert.doesNotMatch(source, /(?:scheduleApi|academicYearApi)\.(?:create|update|delete|importExcel)/)
})

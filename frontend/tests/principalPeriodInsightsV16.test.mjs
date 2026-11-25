import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { normalizePrincipalPeriodInsights, periodParams, recordedPresenceRate } from '../src/pages/attendance/roles/principalPeriodInsights.js'

const request = { yearId: 1, scope: 'SEMESTER', from: '2026-11-01', to: '2026-11-23' }
const counts = (total, hadir, izin = 0, sakit = 0, alpa = 0) => ({ total, hadir, izin, sakit, alpa })
const subject = (id, name, fields = counts(2, 1, 1)) => ({ subject_id: id, subject_name: name, recorded_students: 1, ...fields })
const classRow = () => ({
  academic_year_id: 1, semester: 'GANJIL', class_id: 23, class_name: 'IPA 1', grade_level: 10,
  totals: counts(3, 2, 1), subjects: [subject(2, 'Matematika'), subject(3, 'Bahasa Indonesia', counts(1, 1))],
})
const response = (opts = {}) => ({
  data: { totals: counts(3, 2, 1), classes: [classRow()], ...opts.data },
  meta: { scope: 'SEMESTER', academic_year_id: 1, year: '2026/2027', unit: 'stored_student_meeting_records',
    periods: [{ id: 1, semester: 'GANJIL' }], date_from: request.from, date_to: request.to, ...opts.meta },
})

test('mode rentang tanggal memerlukan filter periode valid dan tidak membalik tanggal', () => {
  assert.deepEqual(periodParams(request), {
    academic_year_id: 1, scope: 'SEMESTER', date_from: request.from, date_to: request.to,
  })
  assert.deepEqual(periodParams({ yearId: 1, scope: 'YEAR' }), { academic_year_id: 1, scope: 'YEAR' })
  assert.throws(() => periodParams({ ...request, to: '2026-10-30' }), /tanggal/i)
  assert.throws(() => periodParams({ ...request, from: '2026-02-30' }), /tanggal/i)
  assert.throws(() => periodParams({ ...request, yearId: 'x' }), /periode/i)
})

test('aggregate menghitung satu catatan untuk satu siswa pada tiap pertemuan mapel', () => {
  const normalized = normalizePrincipalPeriodInsights(response(), request)
  assert.deepEqual(normalized.totals, counts(3, 2, 1))
  assert.equal(normalized.classes.length, 1)
  assert.equal(normalized.classes[0].label, 'X IPA 1')
  assert.equal(normalized.classes[0].subjects.length, 2)
  assert.equal(normalized.classes[0].subjects[0].name, 'Matematika')
  assert.ok(Math.abs(recordedPresenceRate(normalized.totals) - 200 / 3) < 0.0001)
})

test('nol catatan bukan nol persen hadir: denominator wajib ada', () => {
  const empty = normalizePrincipalPeriodInsights(response({ data: { totals: counts(0, 0), classes: [] } }), request)
  assert.equal(recordedPresenceRate(empty.totals), null)
  assert.equal(recordedPresenceRate(counts(1, 0, 0, 0, 1)), 0)
})

test('menolak respons yang salah periode, status, agregasi atau identitas', () => {
  assert.throws(() => normalizePrincipalPeriodInsights(response({ meta: { scope: 'YEAR' } }), request), /konteks/i)
  assert.throws(() => normalizePrincipalPeriodInsights(response({ meta: { academic_year_id: 4 } }), request), /konteks/i)
  assert.throws(() => normalizePrincipalPeriodInsights(response({ data: { totals: counts(9, 9) } }), request), /total/i)
  assert.throws(() => normalizePrincipalPeriodInsights(response({ data: { classes: [{ ...classRow(), subjects: [subject(2, 'Matematika', counts(2, 2))] }] } }), request), /total/i)
  assert.throws(() => normalizePrincipalPeriodInsights(response({ data: { classes: [classRow(), classRow()] } }), request), /duplikat/i)
  assert.throws(() => normalizePrincipalPeriodInsights(response({ data: { classes: [{ ...classRow(), subjects: [subject(3, 'Bahasa Indonesia', { total: -1, hadir: -1, izin: 0, sakit: 0, alpa: 0 })] }] } }), request), /jumlah/i)
})

test('dua semester digabung hanya jika metadata valid; tanpa mengarang semester lain', () => {
  const yearReq = { yearId: 1, scope: 'YEAR' }
  const payload = response({ meta: { scope: 'YEAR', periods: [{ id: 1, semester: 'GANJIL' }, { id: 2, semester: 'GENAP' }], date_from: null, date_to: null } })
  assert.equal(normalizePrincipalPeriodInsights(payload, yearReq).periods.length, 2)
  const single = response({ meta: { scope: 'YEAR', date_from: null, date_to: null } })
  assert.equal(normalizePrincipalPeriodInsights(single, yearReq).periods.length, 1)
  assert.throws(() => normalizePrincipalPeriodInsights(response({ meta: { scope: 'YEAR', periods: [{ id: 1, semester: 'GANJIL' }, { id: 8, semester: 'GANJIL' }] } }), yearReq), /semester/i)
})

test('kode menjaga dashboard harian terpisah, tanpa menambahkan operasi tulis', () => {
  const page = readFileSync(new URL('../src/pages/attendance/roles/KepalaSekolahAttendancePage.jsx', import.meta.url), 'utf8')
  const period = readFileSync(new URL('../src/pages/attendance/roles/PrincipalPeriodInsights.jsx', import.meta.url), 'utf8')
  assert.match(page, /attendanceApi\.getClassRecap/)
  assert.match(page, /mode === 'daily'/)
  assert.match(page, /mode === 'period'/)
  assert.match(period, /apiClient\.get\(`\/attendance\/principal-insights/)
  assert.doesNotMatch(page + period, /apiClient\.(post|put|patch|delete)\s*\(/)
})

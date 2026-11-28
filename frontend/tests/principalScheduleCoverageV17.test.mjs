import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  scheduleCoverageParams, normalizePrincipalScheduleCoverage, recordedScheduleRate,
} from '../src/pages/attendance/roles/PrincipalScheduleCoverage.js'

const request = { yearId: 7, date: '2026-11-23', classId: '' }
const schedule = (id, state, students, recorded, timeState = 'finished') => ({
  schedule_id: id, class_id: 12, class_name: 'IPA 1', grade_level: 10,
  subject_name: `Mapel ${id}`, teacher_name: 'Guru Contoh',
  start_time: '07:00', end_time: '08:00', students,
  recorded_students: recorded, time_state: timeState, state,
})
const baseRows = [
  schedule(1, 'complete', 2, 2), schedule(2, 'partial', 3, 1),
  schedule(3, 'without_records', 2, 0), schedule(4, 'not_finished', 2, 0, 'upcoming'),
  schedule(5, 'without_students', 0, 0),
]
const counts = { scheduled: 5, with_students: 4, evaluated: 3, with_records: 2, complete: 1, partial: 1, without_records: 1, not_finished: 1, without_students: 1 }
const payload = (opts = {}) => ({
  data: { summary: counts, schedules: baseRows, ...opts.data },
  meta: { academic_year_id: 7, date: '2026-11-23', weekday: 'Senin', class_id: null, unit: 'weekly_schedule_with_saved_student_records', is_actual_lesson_confirmation: false, ...opts.meta },
})

test('parameter hanya menerima satu periode, satu tanggal benar dan satu kelas opsional', () => {
  assert.deepEqual(scheduleCoverageParams(request), { academic_year_id: 7, date: '2026-11-23' })
  assert.deepEqual(scheduleCoverageParams({ ...request, classId: '12' }), { academic_year_id: 7, date: '2026-11-23', class_id: 12 })
  assert.throws(() => scheduleCoverageParams({ ...request, yearId: 'x' }), /periode|tanggal/i)
  assert.throws(() => scheduleCoverageParams({ ...request, date: '2026-02-30' }), /tanggal/i)
  assert.throws(() => scheduleCoverageParams({ ...request, classId: 'x' }), /kelas/i)
})

test('jadwal selesai dengan setidaknya satu catatan berbeda dari catatan siswa lengkap', () => {
  const view = normalizePrincipalScheduleCoverage(payload(), request)
  assert.equal(recordedScheduleRate(view.summary), 200 / 3)
  assert.equal(view.summary.complete, 1)
  assert.equal(view.summary.partial, 1)
  assert.equal(view.rows[0].classLabel, 'X IPA 1')
  assert.equal(view.rows[1].state, 'partial')
})

test('jadwal belum selesai dan kelas kosong tidak dihitung sebagai jadwal tanpa catatan', () => {
  const view = normalizePrincipalScheduleCoverage(payload(), request)
  assert.equal(view.summary.evaluated, 3)
  assert.equal(view.summary.not_finished, 1)
  assert.equal(view.summary.without_students, 1)
  assert.equal(view.rows[3].state, 'not_finished')
})

test('denominator nol harus menghasilkan null, bukan 0 atau 100 palsu', () => {
  const empty = payload({ data: { summary: Object.fromEntries(Object.keys(counts).map(k => [k, 0])), schedules: [] } })
  const view = normalizePrincipalScheduleCoverage(empty, request)
  assert.equal(recordedScheduleRate(view.summary), null)
  const none = payload({ data: { summary: { scheduled: 1, with_students: 1, evaluated: 1, with_records: 0, complete: 0, partial: 0, without_records: 1, not_finished: 0, without_students: 0 }, schedules: [schedule(1, 'without_records', 2, 0)] } })
  assert.equal(recordedScheduleRate(normalizePrincipalScheduleCoverage(none, request).summary), 0)
})

test('respons harus sesuai tahun, tanggal, kelas dan satuan monitoring', () => {
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ meta: { academic_year_id: 10 } }), request), /konteks/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ meta: { date: '2026-11-24' } }), request), /konteks/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ meta: { unit: 'student_unique_days' } }), request), /konteks/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ meta: { is_actual_lesson_confirmation: true } }), request), /konteks/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload(), { ...request, classId: '12' }), /konteks/i)
})

test('duplikat jadwal, negatif, jumlah siswa lebih besar dan state bertentangan ditolak', () => {
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ data: { schedules: [...baseRows.slice(0, 4), baseRows[0]] } }), request), /duplikat/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ data: { schedules: [{ ...baseRows[0], recorded_students: 3 }, ...baseRows.slice(1)] } }), request), /jumlah/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ data: { schedules: [{ ...baseRows[0], state: 'partial' }, ...baseRows.slice(1)] } }), request), /status/i)
  assert.throws(() => normalizePrincipalScheduleCoverage(payload({ data: { summary: { ...counts, with_records: 20 } } }), request), /ringkasan/i)
})

test('semua data berasal dari API GET khusus Kepala Sekolah, tidak ada perubahan data atau routing di frontend', () => {
  const jsx = readFileSync(new URL('../src/pages/attendance/roles/PrincipalScheduleCoverage.jsx', import.meta.url), 'utf8')
  const page = readFileSync(new URL('../src/pages/attendance/roles/KepalaSekolahAttendancePage.jsx', import.meta.url), 'utf8')
  assert.match(jsx, /apiClient\.get\(`\/attendance\/principal-schedule-coverage/)
  assert.doesNotMatch(jsx, /apiClient\.(post|put|delete|patch)\s*\(/)
  assert.match(page, /<PrincipalScheduleCoverage/)
  assert.match(page, /<RecapTable/)
  assert.match(page, /<PrincipalPeriodInsights/)
})

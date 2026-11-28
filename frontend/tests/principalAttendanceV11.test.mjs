import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import {
  attendanceRate,
  attendanceTrendGeometry,
  formatAttendanceRate,
  normalizePrincipalAttendance,
  normalizePrincipalRecap,
  principalAttendanceMonths,
} from '../src/pages/dashboard/roles/principalAttendanceView.js'

const base = dirname(fileURLToPath(import.meta.url))
const empty = () => ({ data: { total: 0, hadir: 0, izin: 0, sakit: 0, alpa: 0 } })
const valid = (hadir, izin, sakit, alpa) => ({ data: { total: hadir + izin + sakit + alpa, hadir, izin, sakit, alpa } })

test('six month windows use WIB and cap current month on today', () => {
  const months = principalAttendanceMonths(new Date('2026-11-23T10:00:00+07:00'))
  assert.equal(months.length, 6)
  assert.deepEqual(months.map((month) => month.key), [
    '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11',
  ])
  assert.equal(months[0].from, '2026-06-01')
  assert.equal(months[0].to, '2026-06-30')
  assert.equal(months[5].to, '2026-11-23')
})

test('WIB calendar boundary and year rollover are stable regardless of client timezone', () => {
  const windows = principalAttendanceMonths(new Date('2026-12-31T18:10:00Z'))
  assert.equal(windows[5].key, '2027-01')
  assert.equal(windows[5].to, '2027-01-01')
  assert.equal(windows[0].key, '2026-08')
})

test('invalid date input is rejected rather than silently projected', () => {
  assert.throws(() => principalAttendanceMonths(new Date('bad')), /tidak valid/)
})

test('recap preserves genuine zero records and zero presence from recorded absences', () => {
  assert.deepEqual(normalizePrincipalRecap(empty()), empty().data)
  assert.equal(attendanceRate(normalizePrincipalRecap(empty())), null)
  assert.equal(attendanceRate(normalizePrincipalRecap(valid(0, 0, 0, 4))), 0)
  assert.equal(formatAttendanceRate(0), '0%')
  assert.equal(formatAttendanceRate(null), '—')
})

test('recap rejects wrong envelopes and missing status fields', () => {
  assert.throws(() => normalizePrincipalRecap({ total: 0 }), /tidak valid/)
  assert.throws(() => normalizePrincipalRecap({ data: { total: 0, hadir: 0 } }), /tidak lengkap/)
})

test('recap rejects inconsistent totals, negatives, fractions and nonnumeric values', () => {
  assert.throws(() => normalizePrincipalRecap({ data: { ...valid(2, 1, 0, 0).data, total: 2 } }), /tidak cocok/)
  assert.throws(() => normalizePrincipalRecap({ data: { ...valid(2, 1, 0, 0).data, izin: -1 } }), /tidak valid/)
  assert.throws(() => normalizePrincipalRecap({ data: { ...valid(2, 1, 0, 0).data, hadir: 1.5 } }), /tidak valid/)
  assert.throws(() => normalizePrincipalRecap({ data: { ...valid(2, 1, 0, 0).data, hadir: '2 cats' } }), /tidak valid/)
  assert.throws(() => normalizePrincipalRecap({ data: { ...valid(2, 1, 0, 0).data, total: Number.MAX_SAFE_INTEGER + 1 } }), /tidak valid/)
})

test('six months aggregate only server-confirmed meeting records', () => {
  const months = principalAttendanceMonths(new Date('2026-11-23T10:00:00+07:00'))
  const recap = normalizePrincipalAttendance(months, [empty(), valid(2, 1, 0, 0), empty(), valid(0, 0, 0, 2), empty(), valid(3, 0, 1, 0)])
  assert.equal(recap.totals.total, 9)
  assert.equal(recap.totals.hadir, 5)
  assert.equal(recap.totals.izin, 1)
  assert.equal(recap.totals.sakit, 1)
  assert.equal(recap.totals.alpa, 2)
  assert.equal(recap.monthsWithRecords, 3)
  assert.equal(recap.months[0].rate, null)
  assert.equal(recap.months[3].rate, 0)
  assert.equal(recap.from, '2026-06-01')
  assert.equal(recap.to, '2026-11-23')
})

test('empty source data remains empty; dashboard does not invent attendance percentage', () => {
  const months = principalAttendanceMonths(new Date('2026-11-23T10:00:00+07:00'))
  const recap = normalizePrincipalAttendance(months, Array.from({ length: 6 }, empty))
  assert.equal(recap.totals.total, 0)
  assert.equal(recap.totals.rate, null)
  assert.equal(recap.monthsWithRecords, 0)
})

test('partial responses and invalid month responses fail the whole trend', () => {
  const months = principalAttendanceMonths(new Date('2026-11-23T10:00:00+07:00'))
  assert.throws(() => normalizePrincipalAttendance(months, [empty()]), /tidak lengkap/)
  assert.throws(() => normalizePrincipalAttendance(months, [empty(), empty(), empty(), empty(), empty(), { data: { total: 5 } }]), /tidak lengkap/)
})

test('trend gaps are not connected and valid 0% is a plotted point', () => {
  const rates = [75, null, 0, 100, 40, null]
  const geometry = attendanceTrendGeometry(rates.map((rate, i) => ({ key: String(i), label: String(i), rate, total: rate === null ? 0 : 10 })))
  assert.equal(geometry.points.length, 6)
  assert.equal(geometry.points[1].y, null)
  assert.equal(geometry.points[2].y, 139)
  assert.equal(geometry.segments.length, 1)
  assert.deepEqual(geometry.segments[0].map((point) => point.key), ['2', '3', '4'])
})

test('principal API is read-only, scoped by academic year and bounded dates', () => {
  const api = readFileSync(resolve(base, '../src/services/principalAttendanceApi.js'), 'utf8')
  assert.match(api, /apiClient\.get\(`/)
  assert.match(api, /\/principal\/attendance-summary\?/)
  assert.match(api, /academic_year_id:/)
  assert.match(api, /date_from:/)
  assert.match(api, /date_to:/)
  assert.doesNotMatch(api, /apiClient\.(post|put|delete)\(/)
  assert.doesNotMatch(api, /student_id:/)
})

test('Kepala Sekolah dashboard handles separate loading, retry and no writes', () => {
  const jsx = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.jsx'), 'utf8')
  assert.match(jsx, /normalizePrincipalAttendance\(windows, responses\.map/)
  assert.match(jsx, /principalAttendanceApi\.getMonthlyRecap/)
  assert.match(jsx, /response\.meta\.date_from!==windows\[index\]\.from/)
  assert.match(jsx, /PrincipalAttendanceAnalytics/)
  assert.match(jsx, /setAnalyticsAttempt/)
  assert.match(jsx, /if \(active\) setAnalytics/)
  assert.match(jsx, /\brole="img"/)
  assert.match(jsx, /Mode monitoring/)
  assert.doesNotMatch(jsx, /dashboardApi\.(post|put|delete)/)
})

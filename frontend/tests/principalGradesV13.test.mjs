import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { normalizePrincipalGradeRecap, formatPrincipalGrade, principalGradeBarWidth } from '../src/pages/dashboard/roles/principalGradeView.js'
const base = dirname(fileURLToPath(import.meta.url))
const full = (total, avg, min, max) => ({ data: { total, rata_rata: avg, terendah: min, tertinggi: max } })

test('rekap nilai mempertahankan nilai nol yang benar dengan catatan tercatat', () => {
  const value = normalizePrincipalGradeRecap(full(3, 0, 0, 0))
  assert.deepEqual(value, { total: 3, average: 0, lowest: 0, highest: 0 })
  assert.equal(formatPrincipalGrade(0), '0')
  assert.equal(principalGradeBarWidth(0), 0)
})

test('periode tanpa nilai bukan rata-rata nol', () => {
  assert.deepEqual(normalizePrincipalGradeRecap(full(0, 0, null, null)), { total: 0, average: null, lowest: null, highest: null })
  assert.equal(formatPrincipalGrade(null), '—')
  assert.throws(() => normalizePrincipalGradeRecap(full(0, 0, 0, null)), /tidak konsisten/)
})

test('angka valid menerima serialisasi Laravel decimal string', () => {
  assert.deepEqual(normalizePrincipalGradeRecap(full('3', '72.50', '60.00', '90.00')), { total: 3, average: 72.5, lowest: 60, highest: 90 })
  assert.equal(formatPrincipalGrade(72.5), '72,5')
  assert.equal(principalGradeBarWidth(72.5), 72.5)
})

test('respons rekap harus memakai envelope data yang benar', () => {
  assert.throws(() => normalizePrincipalGradeRecap({ total: 3 }), /tidak valid/)
  assert.throws(() => normalizePrincipalGradeRecap(null), /tidak valid/)
  assert.throws(() => normalizePrincipalGradeRecap({ data: { total: 2 } }), /tidak lengkap/)
})

test('nilai negatif, lebih dari 100 dan statistik yang bertentangan ditolak', () => {
  assert.throws(() => normalizePrincipalGradeRecap(full(2, -3, 0, 80)), /tidak lengkap/)
  assert.throws(() => normalizePrincipalGradeRecap(full(2, 84, 20, 101)), /tidak lengkap/)
  assert.throws(() => normalizePrincipalGradeRecap(full(2, 30, 70, 80)), /tidak konsisten/)
  assert.throws(() => normalizePrincipalGradeRecap(full(2.5, 75, 50, 100)), /tidak valid/)
  assert.throws(() => normalizePrincipalGradeRecap(full(2, 'not a score', 0, 100)), /tidak lengkap/)
})

test('rerata bukan rapor akhir dan visual hanya dari rentang 0-100', () => {
  assert.equal(principalGradeBarWidth(-1), 0)
  assert.equal(principalGradeBarWidth(101), 0)
  assert.equal(principalGradeBarWidth(null), 0)
  assert.equal(principalGradeBarWidth(99.5), 99.5)
})

test('API analitik nilai hanya GET, wajib periode aktif dan tanpa identitas siswa', () => {
  const api = readFileSync(resolve(base, '../src/services/principalGradeApi.js'), 'utf8')
  assert.match(api, /apiClient.get\(`/)
  assert.match(api, /principal\/grade-summary\?/)
  assert.match(api, /academic_year_id:/)
  assert.doesNotMatch(api, /apiClient\.(post|put|delete)/)
  assert.doesNotMatch(api, /student_id:/)
})

test('dashboard memiliki error, empty, retry dan proteksi respons asinkron', () => {
  const jsx = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.jsx'), 'utf8')
  assert.match(jsx, /<PrincipalGradeAnalytics/)
  assert.match(jsx, /principalGradeApi.getRecap/)
  assert.match(jsx, /normalizePrincipalGradeRecap/)
  assert.match(jsx, /setGradeAttempt/)
  assert.match(jsx, /if \(active\) setGradeState/)
  assert.match(jsx, /Tidak mewakili|tidak mewakili/)
  assert.match(jsx, /Belum ada catatan nilai komponen/)
})

test('label tingkat kelas memakai tingkat resmi dan tidak menggandakan prefix', async () => {
  const { normalizePrincipalDashboard } = await import('../src/pages/dashboard/roles/principalDashboardView.js')
  const payload = { data: { role: 'KEPALA_SEKOLAH', charts: { students_by_class: [
    { class_id: 1, class_name: 'IPA 1', tingkat: 10, student_count: 9 },
    { class_id: 2, class_name: 'IPA 1', tingkat: 11, student_count: 8 },
    { class_id: 3, class_name: 'XII IPA 1', tingkat: 12, student_count: 7 },
  ] } } }
  assert.deepEqual(normalizePrincipalDashboard(payload).classes.map((i) => i.name), ['X IPA 1', 'XI IPA 1', 'XII IPA 1'])
})

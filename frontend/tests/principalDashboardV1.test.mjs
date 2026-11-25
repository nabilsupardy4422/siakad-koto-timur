import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import {
  classBarWidth,
  formatPrincipalNumber,
  normalizePrincipalDashboard,
} from '../src/pages/dashboard/roles/principalDashboardView.js'

const base = dirname(fileURLToPath(import.meta.url))

const payload = {
  data: {
    role: 'KEPALA_SEKOLAH',
    summary: {
      total_guru: 22,
      total_siswa: 0,
      total_kelas: 3,
      total_jadwal: '19',
      active_academic_year: { id: 3, tahun_mulai: 2026, tahun_selesai: 2027, semester: 'ganjil', is_active: true },
    },
    charts: {
      students_by_class: [
        { class_id: 2, class_name: 'XI IPA', student_count: 23 },
        { class_id: 1, class_name: 'X IPA', student_count: 0 },
      ],
    },
    recent: { academic_years: [{ id: 3, tahun_mulai: 2026, tahun_selesai: 2027, semester: 'ganjil', is_active: true }] },
  },
}

test('Kepala Sekolah hanya menerima payload dengan role yang tepat', () => {
  assert.throws(() => normalizePrincipalDashboard({ data: { role: 'TU' } }), /tidak sesuai/)
  assert.throws(() => normalizePrincipalDashboard(null), /tidak sesuai/)
})

test('angka nol tidak disamakan dengan data tidak tersedia', () => {
  const data = normalizePrincipalDashboard(payload)
  assert.equal(data.metrics.find((item) => item.key === 'siswa').value, 0)
  assert.equal(formatPrincipalNumber(0), '0')
  assert.equal(formatPrincipalNumber(null), '—')
  assert.equal(data.metrics.find((item) => item.key === 'jadwal').value, 19)
})

test('periode aktif dan kelas ditafsirkan dari kontrak API nyata', () => {
  const data = normalizePrincipalDashboard(payload)
  assert.equal(data.year.label, '2026/2027')
  assert.equal(data.year.semester, 'Ganjil')
  assert.equal(data.classes[0].name, 'XI IPA')
  assert.equal(data.classes[1].count, 0)
  assert.equal(data.years[0].isActive, true)
})

test('kelas asing, duplikat, hitungan tidak valid tidak dihitung', () => {
  const contaminated = structuredClone(payload)
  contaminated.data.charts.students_by_class.push(
    { class_id: 2, class_name: 'Duplikat', student_count: 5 },
    { class_id: 3, class_name: 'Negatif', student_count: -8 },
    { class_id: 4, class_name: '', student_count: 5 },
  )
  assert.equal(normalizePrincipalDashboard(contaminated).classes.length, 2)
})

test('data statistik tak ada tidak menjadi nol buatan', () => {
  const data = normalizePrincipalDashboard({ role: 'KEPALA_SEKOLAH', summary: {}, charts: {} })
  assert.equal(data.metrics[0].value, null)
  assert.equal(data.year, null)
  assert.deepEqual(data.classes, [])
  assert.equal(data.metrics[2].note, 'Dari seluruh periode tercatat')
})

test('bar chart hanya menggambar presentasi relatif, tanpa inventarisasi data', () => {
  assert.equal(classBarWidth(0, 20), 0)
  assert.equal(classBarWidth(10, 20), 50)
  assert.equal(classBarWidth(10, 0), 0)
})

test('dashboard memakai API baca dan tautan ke modul yang telah ada', () => {
  const jsx = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.jsx'), 'utf8')
  assert.match(jsx, /dashboardApi\.getDashboard\(\)/)
  assert.match(jsx, /\/app\/attendance/)
  assert.match(jsx, /\/app\/grades/)
  assert.match(jsx, /\/app\/schedules/)
  assert.match(jsx, /\/app\/reports/)
  assert.doesNotMatch(jsx, /dashboardApi\.(post|put|delete)/)
})

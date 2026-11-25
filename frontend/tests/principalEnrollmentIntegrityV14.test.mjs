import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { principalEnrollmentScope, normalizePrincipalDashboard } from '../src/pages/dashboard/roles/principalDashboardView.js'

const base = dirname(fileURLToPath(import.meta.url))

test('4 profil siswa dan 3 keanggotaan tidak ditafsirkan sebagai siswa belum ditempatkan', () => {
  const scope = principalEnrollmentScope([{ count: 2 }, { count: 1 }, { count: 0 }], { value: 4 })
  assert.deepEqual(scope, { registeredProfiles: 4, memberships: 3, displayedClasses: 3 })
  const jsx = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.jsx'), 'utf8')
  assert.match(jsx, /Selisih kedua angka bukan bukti jumlah siswa tanpa kelas/)
  assert.doesNotMatch(jsx, /4 profil berarti 1 siswa belum/i)
})

test('perbandingan tidak mengubah jumlah kelas yang benar-benar ditampilkan', () => {
  const scope = principalEnrollmentScope([{ count: 20 }, { count: 10 }], { value: 100 })
  assert.equal(scope.memberships, 30)
  assert.equal(scope.displayedClasses, 2)
})

test('nol asli, nilai kosong, dan data kelas yang belum ada tetap berbeda', () => {
  assert.equal(principalEnrollmentScope([{ count: 0 }], { value: 0 }).registeredProfiles, 0)
  assert.equal(principalEnrollmentScope([{ count: 0 }], { value: null }).registeredProfiles, null)
  assert.equal(principalEnrollmentScope([], { value: 10 }), null)
})

test('hitungan negatif, pecahan, dan overflow ditolak', () => {
  assert.equal(principalEnrollmentScope([{ count: -1 }], { value: 2 }), null)
  assert.equal(principalEnrollmentScope([{ count: 1.5 }], { value: 2 }), null)
  assert.equal(principalEnrollmentScope([{ count: Number.MAX_SAFE_INTEGER }, { count: 1 }], { value: 2 }), null)
})

test('ringkasan tetap menggunakan data API dan tidak mengubah hak akses atau endpoints', () => {
  const dashboard = normalizePrincipalDashboard({
    role: 'KEPALA_SEKOLAH',
    summary: { total_siswa: 4 },
    charts: { students_by_class: [{ class_id: 10, class_name: 'IPA 1', tingkat: 10, student_count: 2 }] },
  })
  assert.equal(principalEnrollmentScope(dashboard.classes, dashboard.metrics.find((row) => row.key === 'siswa')).memberships, 2)
  const jsx = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.jsx'), 'utf8')
  assert.match(jsx, /\/app\/attendance/)
  assert.match(jsx, /\/app\/grades/)
  assert.match(jsx, /\/app\/schedules/)
  assert.match(jsx, /\/app\/reports/)
  assert.doesNotMatch(jsx, /apiClient\.(post|put|delete)/)
})

test('panel data tetap responsif dan tidak melewati batas perangkat kecil', () => {
  const css = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.css'), 'utf8')
  assert.match(css, /principal-v1__enrollment-scope/)
  assert.match(css, /max-width: 470px/)
  assert.match(css, /grid-template-columns: minmax\(0, 1fr\)/)
})

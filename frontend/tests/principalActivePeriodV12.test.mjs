import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
const base = dirname(fileURLToPath(import.meta.url))
const jsx = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.jsx'), 'utf8')
const css = readFileSync(resolve(base, '../src/pages/dashboard/roles/KepalaSekolahDashboard.css'), 'utf8')

test('periode sekolah hanya menerima tahun akademik aktif', () => {
  assert.match(jsx, /function PeriodPanel\(\{ year \}\)/)
  assert.match(jsx, /<PeriodPanel year=\{year\} \/>/)
  assert.doesNotMatch(jsx, /<PeriodPanel[^>]*years=/)
  assert.doesNotMatch(jsx, /years\.map\(/)
  assert.doesNotMatch(jsx, /Periode tercatat/)
})
test('periode menampilkan semester aktif dan status belum ada', () => {
  assert.match(jsx, /year\?\.label \?\? 'Belum ditentukan'/)
  assert.match(jsx, /Semester \$\{year\.semester\}/)
  assert.match(jsx, /!hasPeriod/)
  assert.match(jsx, /principal-v1__period-warning/)
})
test('periode ringkas tidak meregang dan tidak merusak analitik presensi', () => {
  assert.match(css, /\.principal-v1__insights-grid \{ display: grid; align-items: start;/)
  assert.match(css, /\.principal-v1__period-helper/)
  assert.match(jsx, /<PrincipalAttendanceAnalytics/)
  assert.match(jsx, /<DistributionPanel classes=\{classes\}/)
})

test('tren satu bulan diberi konteks agar 100 persen tidak dianggap tren enam bulan', () => {
  assert.match(jsx, /attendance\.monthsWithRecords < 2/)
  assert.match(jsx, /Belum cukup data untuk membandingkan tren antarbulan/)
  assert.match(css, /\.principal-v1__trend-insight/)
})

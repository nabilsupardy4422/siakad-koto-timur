import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import {
  formatCount, isISODate, normalizePrincipalDailyRecap,
  principalClassLabel, principalCoverage, principalDailyTotals, todayWIB,
} from '../src/pages/attendance/roles/principalDailyRecap.js'

const context = { yearId: 5, date: '2026-11-23', semester: 'GANJIL' }
const row = (id, extra = {}) => ({
  class_id: id, nama_kelas: 'IPA 1', tingkat: id === 1 ? 10 : 12,
  total_siswa: 2, hadir: 1, izin: 0, sakit: 0, alpa: 0, belum_ada_data: 1, ...extra,
})
const response = (rows = [row(1), row(2)], meta = {}) => ({
  data: rows, meta: {
    date: context.date, academic_year_id: context.yearId, semester: context.semester,
    total_classes: rows.length, ...meta,
  },
})

test('tanggal WIB tidak bergantung timezone perangkat', () => {
  assert.equal(todayWIB(new Date('2026-11-22T18:10:00Z')), '2026-11-23')
  assert.equal(isISODate('2026-02-29'), false)
  assert.equal(isISODate('2028-02-29'), true)
  assert.equal(isISODate('2026-13-01'), false)
})
test('label kelas menyertakan tingkat tanpa menambahkan prefix ganda', () => {
  assert.equal(principalClassLabel(row(1)), 'X IPA 1')
  assert.equal(principalClassLabel(row(2)), 'XII IPA 1')
  assert.equal(principalClassLabel({ nama_kelas: 'XI IPA 2', tingkat: 11 }), 'XI IPA 2')
})
test('semua kelas dihitung sesuai respons backend tanpa mengubah satuan harian', () => {
  const { rows } = normalizePrincipalDailyRecap(response(), context)
  assert.equal(rows.length, 2)
  assert.deepEqual(rows.map(x => x.label), ['X IPA 1', 'XII IPA 1'])
  assert.deepEqual(principalDailyTotals(rows), {
    total_siswa: 4, hadir: 2, izin: 0, sakit: 0, alpa: 0, belum_ada_data: 2,
  })
  assert.equal(principalCoverage(principalDailyTotals(rows)), 50)
})
test('tidak ada catatan tidak sama dengan Alpa dan tidak dianggap ketidakhadiran', () => {
  const { rows } = normalizePrincipalDailyRecap(response([row(1, {
    total_siswa: 3, hadir: 0, belum_ada_data: 3,
  })]), context)
  assert.equal(rows[0].alpa, 0)
  assert.equal(rows[0].recorded, 0)
  assert.equal(rows[0].completion, 0)
})
test('kelas kosong tidak memunculkan persentase palsu', () => {
  const { rows } = normalizePrincipalDailyRecap(response([row(1, {
    total_siswa: 0, hadir: 0, belum_ada_data: 0,
  })]), context)
  assert.equal(rows[0].completion, null)
  assert.equal(principalCoverage(principalDailyTotals(rows)), null)
  assert.equal(formatCount(0), '0')
})
test('respons di luar periode ditolak dan tidak boleh dipakai', () => {
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1)], { academic_year_id: 8 }), context), /Konteks/)
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1)], { date: '2026-11-24' }), context), /Konteks/)
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1)], { semester: 'GENAP' }), context), /Konteks/)
})
test('duplikat, total tidak konsisten, minus, dan data tidak valid ditolak', () => {
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1), row(1)]), context), /duplikat/)
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1, { total_siswa: 7 })]), context), /Total presensi/)
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1, { alpa: -1 })]), context), /tidak valid/)
  assert.throws(() => normalizePrincipalDailyRecap(response([row(1, { hadir: 1.5 })]), context), /tidak valid/)
  assert.throws(() => normalizePrincipalDailyRecap({ data: [], meta: null }, context), /kontrak/)
})
test('kelas dari respon boleh difilter lokal tanpa meminta class_id milik orang lain', () => {
  const { rows } = normalizePrincipalDailyRecap(response(), context)
  const filtered = rows.filter(x => String(x.id) === '2')
  assert.deepEqual(filtered.map(x => x.label), ['XII IPA 1'])
  assert.equal(principalDailyTotals(filtered).total_siswa, 2)
})
test('Kepala Sekolah memakai halaman sendiri; TU dan Guru tidak dimodifikasi', () => {
  const routing = readFileSync(new URL('../src/pages/attendance/AttendancePage.jsx', import.meta.url), 'utf8')
  const page = readFileSync(new URL('../src/pages/attendance/roles/KepalaSekolahAttendancePage.jsx', import.meta.url), 'utf8')
  assert.match(routing, /currentRole === 'TU'[\s\S]*<TUAttendancePage/)
  assert.match(routing, /currentRole === 'KEPALA_SEKOLAH'[\s\S]*<KepalaSekolahAttendancePage/)
  assert.match(page, /attendanceApi\.getClassRecap/)
  assert.doesNotMatch(page, /attendanceApi\.(create|update|bulkSave)\s*\(/)
  assert.match(page, /Kelengkapan data bukan persentase kehadiran/)
  assert.match(page, /Belum Tercatat/)
})

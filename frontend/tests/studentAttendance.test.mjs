import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { timeText, initials, teacherNames, getSummary, periodText, textStatus, totalSummary, presentPercentage, isInvalidDateRange } from '../src/pages/attendance/roles/studentAttendanceView.js'
const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const page = readFileSync(resolve(root,'src/pages/attendance/roles/SiswaAttendancePage.jsx'), 'utf8')

test('dua guru pada mapel yang sama ditampilkan sekali per nama', () => {
 assert.deepEqual(teacherNames({schedules:[{teacher:'Haikal'},{teacher:'Nabil Supardy'},{teacher:'Haikal'}]}),['Haikal','Nabil Supardy'])
})
test('rekap per-mapel dijumlahkan tanpa mengubah nol', () => {
 const totals = totalSummary([{summary:{total:2,HADIR:1,IZIN:1,SAKIT:0,ALPA:0}}, {summary:{total:3,HADIR:2,IZIN:0,SAKIT:1,ALPA:0}}])
 assert.deepEqual(totals,{total:5,HADIR:3,IZIN:1,SAKIT:1,ALPA:0})
 assert.equal(presentPercentage(totals),60)
})
test('tanpa catatan tidak dianggap Alpa dan tidak ditampilkan persen palsu', () => {
 const totals=totalSummary([{summary:{total:0,HADIR:0,ALPA:0}}])
 assert.equal(presentPercentage(totals),null)
 assert.equal(totals.ALPA,0)
 assert.equal(getSummary(null).total,undefined)
})
test('rentang tanggal valid dan tidak valid dibedakan', () => {
 assert.equal(isInvalidDateRange('2026-11-23','2026-11-22'),true)
 assert.equal(isInvalidDateRange('2026-11-22','2026-11-22'),false)
 assert.equal(isInvalidDateRange('','2026-11-22'),false)
})
test('tahun dan status akademik dirender dengan benar', () => {
 assert.equal(periodText({tahun_mulai:2026,tahun_selesai:2027,semester:'GANJIL'}),'2026/2027 · Ganjil')
 assert.equal(textStatus('active'),'Penempatan aktif')
 assert.equal(textStatus('scheduled'),'Belum dimulai')
})
test('format jam dan inisial aman untuk data kosong', () => {
 assert.equal(timeText('08:00:00'),'08:00')
 assert.equal(initials('Nabil Supardy'),'NS')
 assert.equal(initials(''),'M')
})
test('detail mapel memakai state React, bukan navigasi/reload browser', () => {
 assert.match(page,/setSubjectId\(subject\.id\)/)
 assert.match(page,/setViewMode\('details'\)/)
 assert.match(page,/type="button"/)
 assert.doesNotMatch(page,/(?:window\.location|location\.reload|document\.location)/)
})
test('UI tidak menghilang hanya karena permintaan riwayat sedang berjalan', () => {
 assert.match(page,/busy && !result/)
 assert.match(page,/busy && result/)
 assert.doesNotMatch(page,/!busy\s*&&\s*ctx/)
})

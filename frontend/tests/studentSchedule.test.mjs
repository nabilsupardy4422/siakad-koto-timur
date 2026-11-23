import test from 'node:test'
import assert from 'node:assert/strict'
import {
  verifyStudentSchedulePage, getCompleteStudentSchedule,
  nextWeeklyClass, wibNow, yearTitle,
} from '../src/pages/schedules/roles/studentScheduleScope.js'

const period = { id: 9, tahun_mulai: 2029, tahun_selesai: 2030, semester: 'GENAP' }
const context = { status: 'active', as_of: '2026-11-22', academic_year: period,
  class: { id: 12, nama: 'IPA 1', tingkat: 12 }, starts_on: '2026-10-27', ends_on: null }
const schedule = (id, day='SENIN', time='07:00:00') => ({ id, tahun_akademik_id: 9, kelas_id: 12,
  guru_id: 4, mapel_id: 5, hari: day, jam_mulai: time, jam_selesai: '09:30:00',
  kelas: { id: 12, nama: 'IPA 1', tingkat: 12 }, tahun_akademik: period, guru: { id: 4, nama_lengkap: 'Guru SIAKAD' },
  mapel: { id: 5, nama: 'Bahasa Indonesia' } })
const page = (items, p=1, last=1, total=items.length, ctx=context) => ({ data:items,
  meta: { current_page:p,last_page:last,per_page:100,total,student_context:ctx } })

test('valid student schedule preserves exact class, academic period, teacher, day and time', () => {
  const checked=verifyStudentSchedulePage(page([schedule(4)]))
  assert.equal(checked.rows[0].guru.nama_lengkap, 'Guru SIAKAD')
  assert.equal(checked.rows[0].jam_mulai, '07:00:00')
  assert.equal(checked.ctx.class.nama,'IPA 1')
  assert.equal(yearTitle(checked.ctx.academic_year),'2029/2030 · Genap')
})

test('future, ended and unassigned contexts cannot expose any schedule', () => {
  for (const status of ['scheduled','ended','unassigned']) {
    const blocked = { ...context, status, class: null }
    assert.equal(verifyStudentSchedulePage(page([],1,1,0,blocked)).rows.length, 0)
    assert.throws(() => verifyStudentSchedulePage(page([schedule(3)],1,1,1,blocked)))
  }
})

test('other class or year, missing teacher, bad time and invalid weekday all fail closed', () => {
  const invalid = [
    {...schedule(1),kelas_id:13}, {...schedule(1),tahun_akademik_id:8},
    {...schedule(1),guru:null}, {...schedule(1),jam_mulai:'11:30:00'},
    {...schedule(1),hari:'HARI LIBUR'}, {...schedule(1),mapel_id:99},
  ]
  for (const entry of invalid) assert.throws(() => verifyStudentSchedulePage(page([entry])))
})

test('all pages fetched, not just first 100; duplicate id and changing scope rejected', async () => {
  const list=[schedule(3,'RABU'),schedule(1,'SENIN'),schedule(2,'SELASA')]
  const getPage=async(p)=>page(p===1?[list[0],list[1]]:[list[2]],p,2,3)
  const fetched=await getCompleteStudentSchedule(getPage)
  assert.deepEqual(fetched.schedules.map(x=>x.id),[1,2,3])
  await assert.rejects(()=>getCompleteStudentSchedule(async(p)=>page([list[0]],p,2,2)))
  await assert.rejects(()=>getCompleteStudentSchedule(async(p)=>page([p===1?list[0]:list[1]],p,2,2,
    p===1?context:{...context,class:{id:44,nama:'IPS 1',tingkat:12}})))
})

test('next class follows stored recurring day and WIB time, including current ongoing slot', () => {
  const mon=schedule(1,'SENIN','07:00:00')
  assert.equal(nextWeeklyClass([mon],{weekday:0,minute:8*60}).schedule.id,1)
  assert.equal(nextWeeklyClass([mon],{weekday:0,minute:10*60}).ahead,7)
  assert.equal(wibNow(new Date('2026-11-22T03:00:00Z')).weekday,6)
})

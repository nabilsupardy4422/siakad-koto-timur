import test from 'node:test'
import assert from 'node:assert/strict'
import { isAttendanceDate, weekdayMismatch, loadAttendanceRoster } from '../src/pages/attendance/roles/attendanceScope.js'
const schedule = {id: 4, kelas_id: 6, hari:'Senin'}
const date = '2026-11-23'
const yearId = 2
const meta = {schedule_id:4,class_id:6,academic_year_id:2,date,current_page:1,last_page:1}
const student = (id) => ({id,nama_lengkap:`Siswa ${id}`,nis:`N${id}`})
const record = (id, siswa_id, extra={}) => ({id,siswa_id,jadwal_pelajaran_id:4,tanggal:date,status:'IZIN',catatan:'Izin',...extra})
const api = (overrides={}) => ({getRoster:async()=>({data:[student(1),student(2)],meta}),getAll:async()=>({data:[record(10,1)],meta:{current_page:1,last_page:1}}),...overrides})
test('strict dates and weekday warning without blocking replacement lessons',()=>{
  assert.equal(isAttendanceDate(date),true); assert.equal(isAttendanceDate('2026-02-30'),false); assert.equal(isAttendanceDate(''),false)
  assert.equal(weekdayMismatch(schedule,date),false); assert.equal(weekdayMismatch(schedule,'2026-11-24'),true)
  assert.equal(weekdayMismatch({hari:'JUMAT'},'2026-11-27'),false)
})
test('existing records are preserved, unrecorded students start blank',async()=>{
  const result=await loadAttendanceRoster(api(),schedule,date,yearId)
  assert.deepEqual(result.map(r=>[r.siswa_id,r.status,r.recorded]),[[1,'IZIN',true],[2,'',false]])
  assert.equal(result[0].catatan,'Izin')
})
test('roster and records are paginated and use exact teaching context',async()=>{
  const calls=[]
  const result=await loadAttendanceRoster(api({getRoster:async p=>{calls.push(['roster',p]);return {data:[student(p.page)],meta:{...meta,current_page:p.page,last_page:2}}},getAll:async p=>{calls.push(['records',p]);return {data:[record(p.page,p.page)],meta:{current_page:p.page,last_page:2}}}}),schedule,date,yearId)
  assert.equal(result.length,2); assert.equal(calls.length,4)
  assert.ok(calls.every(([,p])=>p.schedule_id===4&&p.date===date))
  assert.ok(calls.filter(([k])=>k==='records').every(([,p])=>p.scope==='teaching'&&p.academic_year_id===2))
})
test('wrong roster context or unexpected attendance records fail closed',async()=>{
  await assert.rejects(loadAttendanceRoster(api({getRoster:async()=>({data:[],meta:{...meta,class_id:99}})}),schedule,date,yearId),/Konteks/)
  await assert.rejects(loadAttendanceRoster(api({getAll:async()=>({data:[record(1,1,{jadwal_pelajaran_id:99})]})}),schedule,date,yearId),/konteks/)
  await assert.rejects(loadAttendanceRoster(api({getAll:async()=>({data:[record(1,1,{tanggal:'2026-11-24'})]})}),schedule,date,yearId),/konteks/)
})
test('duplicate or invalid status is rejected; historical rows outside roster are excluded',async()=>{
  await assert.rejects(loadAttendanceRoster(api({getAll:async()=>({data:[record(1,1),record(2,1)]})}),schedule,date,yearId),/duplikat/)
  await assert.rejects(loadAttendanceRoster(api({getAll:async()=>({data:[record(1,1,{status:'? '})]})}),schedule,date,yearId),/valid/)
  const result=await loadAttendanceRoster(api({getAll:async()=>({data:[record(1,100)]})}),schedule,date,yearId)
  assert.deepEqual(result.map(r=>r.status),['',''])
})
test('invalid selection and failed API never create a usable roster',async()=>{
  let called=false
  await assert.rejects(loadAttendanceRoster(api({getRoster:async()=>{called=true}}),schedule,'',yearId),/belum valid/)
  assert.equal(called,false)
  await assert.rejects(loadAttendanceRoster(api({getRoster:async()=>{throw new Error('403')}}),schedule,date,yearId),/403/)
})

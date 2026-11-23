import test from 'node:test'
import assert from 'node:assert/strict'
import {readFile} from 'node:fs/promises'
import {activeAssignments,loadHomeroomContext,recapParams,roster,attendanceSummary,gradeSummary,loadHomeroomData,currentDay} from '../src/pages/homeroom/homeroomScope.js'
const today=currentDay(), year={id:3,is_active:true,semester:'GENAP'}, a={id:1,guru_id:8,kelas_id:4,tahun_akademik_id:3,kelas:{id:4},tahun_akademik:{id:3},tanggal_mulai:today,tanggal_selesai:null}
const m={id:5,kelas_id:4,siswa_id:7,siswa:{id:7,nama_lengkap:'Siswa A',nis:'007',nisn:'0007'},tanggal_mulai:today,tanggal_selesai:null}
const emptyAttendance={data:{total:0,hadir:0,izin:0,sakit:0,alpa:0}}, emptyGrades={data:{total:0,rata_rata:0,tertinggi:null,terendah:null}}
const list=data=>({data,meta:{last_page:1}})
test('only own open effective assignment in active year survives',()=>{
  assert.deepEqual(activeAssignments([a,{...a,id:2,guru_id:9},{...a,id:3,tahun_akademik_id:2},{...a,id:4,tanggal_mulai:'9999-01-01'},{...a,id:5,tanggal_selesai:today},{...a,id:6,kelas:{id:9}}],8,3),[a])
})
test('context loads all pages with explicit scope and refuses multiple active years',async()=>{
 const calls=[];const api={academicYearApi:{getAll:async()=>list([year])},homeroomApi:{getAll:async p=>{calls.push(p);return {data:p.page===1?[a]:[{...a,id:2,guru_id:9}],meta:{last_page:2,current_page:p.page}}}}}
 assert.deepEqual(await loadHomeroomContext(api,8),{year,assignments:[a]});assert.deepEqual(calls.map(p=>[p.page,p.scope,p.teacher_id,p.academic_year_id]),[[1,'homeroom',8,3],[2,'homeroom',8,3]])
 await assert.rejects(loadHomeroomContext({...api,academicYearApi:{getAll:async()=>list([year,{...year,id:4}])}},8),/Lebih dari satu/)
 await assert.rejects(loadHomeroomContext(api,null),/Identitas/)
})
test('roster uses nested NIS and respects membership dates and class',()=>{
 assert.deepEqual(roster([m,{...m,id:6,siswa_id:8,siswa:{id:8},tanggal_mulai:'9999-01-01'}],4),[{id:7,name:'Siswa A',nis:'007',nisn:'0007'}]);assert.throws(()=>roster([{...m,kelas_id:2}],4));assert.throws(()=>roster([m,{...m,id:6}],4));assert.equal(roster([{...m,tanggal_selesai:today}],4).length,1)
})
test('absence of records never represents zero percent; real zero is valid',()=>{
 assert.equal(attendanceSummary(emptyAttendance).percent,null);assert.equal(attendanceSummary({data:{total:1,hadir:0,izin:0,sakit:0,alpa:1}}).percent,0);assert.equal(gradeSummary(emptyGrades).average,null);assert.equal(gradeSummary({data:{total:1,rata_rata:0,tertinggi:0,terendah:0}}).average,0);assert.throws(()=>attendanceSummary({data:{total:1,hadir:1,izin:1,sakit:0,alpa:0}}));assert.throws(()=>gradeSummary({data:{total:1,rata_rata:90,tertinggi:80,terendah:1}}))
})
test('all requests use exact class, year, semester and homeroom scope',async()=>{
 const calls=[]; const api={classMemberApi:{getAll:async (id,p)=>{calls.push([id,p]);return list([m])}},attendanceApi:{getRecap:async p=>{calls.push(p);return emptyAttendance}},gradeApi:{getRecap:async p=>{calls.push(p);return emptyGrades}}};const result=await loadHomeroomData(api,a,year,8);assert.equal(result.students.data.length,1);assert.deepEqual(calls[0],[4,{page:1,per_page:100,scope:'homeroom',academic_year_id:3}]);assert.deepEqual(calls[1],{scope:'homeroom',class_id:4,academic_year_id:3,semester:'GENAP'});assert.deepEqual(calls[2],calls[1]);assert.throws(()=>recapParams(a,year,9))
})
test('partial transport errors are visible and revoked access clears entire workspace',async()=>{
 const api={classMemberApi:{getAll:async()=>list([m])},attendanceApi:{getRecap:async()=>{throw new Error('offline')}},gradeApi:{getRecap:async()=>emptyGrades}};const result=await loadHomeroomData(api,a,year,8);assert.equal(result.attendance.error,'offline');assert.equal(result.attendance.data,null);assert.equal(result.students.data.length,1);api.gradeApi.getRecap=async()=>{throw Object.assign(new Error('forbidden'),{status:403})};await assert.rejects(loadHomeroomData(api,a,year,8),/Akses kelas/)
})

const dataModule = text => 'data:text/javascript;base64,'+Buffer.from(text).toString('base64')
async function service(name){
 const dir=new URL('../src/services/',import.meta.url)
 const error=dataModule(await readFile(new URL('apiError.js',dir),'utf8'))
 const client=dataModule((await readFile(new URL('apiClient.js',dir),'utf8')).replace(/(['"])\.\/apiError\1/,JSON.stringify(error)).replaceAll('import.meta.env.','import.meta.env?.'))
 return import(dataModule((await readFile(new URL(name+'.js',dir),'utf8')).replace(/(['"])\.\/apiClient\1/,JSON.stringify(client)).replaceAll('import.meta.env.','import.meta.env?.')))
}

test('real services send authenticated GET requests with the exact homeroom context',async()=>{
 const calls=[], originalFetch=globalThis.fetch, originalStorage=globalThis.localStorage
 globalThis.localStorage={getItem:()=> 'test-token'}
 globalThis.fetch=async(url,options)=>{calls.push({url:new URL(url),options});const path=new URL(url).pathname;const data=path.endsWith('/academic-years')?list([year]):path.endsWith('/homeroom-assignments')?list([a]):path.endsWith('/students')?list([m]):path.endsWith('/attendance/recap')?emptyAttendance:emptyGrades;return new Response(JSON.stringify(data),{headers:{'content-type':'application/json'}})}
 try {
  const services=Object.assign({},...await Promise.all(['academicYearApi','homeroomApi','classMemberApi','attendanceApi','gradeApi'].map(service)))
  const context=await loadHomeroomContext(services,8);const result=await loadHomeroomData(services,context.assignments[0],context.year,8)
  assert.equal(result.students.data[0].nis,'007');assert.equal(calls.length,5)
  assert.ok(calls.every(c=>c.options.headers.Authorization==='Bearer test-token' && c.options.method==='GET'))
  assert.ok(calls.slice(1).every(c=>c.url.searchParams.get('scope')==='homeroom'&&c.url.searchParams.get('academic_year_id')==='3'))
  assert.ok(calls.filter(c=>c.url.pathname.endsWith('/recap')).every(c=>c.url.searchParams.get('class_id')==='4'&&c.url.searchParams.get('semester')==='GENAP'))
 }finally{globalThis.fetch=originalFetch;if(originalStorage===undefined)delete globalThis.localStorage;else globalThis.localStorage=originalStorage}
})

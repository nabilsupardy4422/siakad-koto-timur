import test from 'node:test'
import assert from 'node:assert/strict'
import {readFile} from 'node:fs/promises'
import {ownSchedules, ownComponents, activeStudents, scopedGrades, buildRows, patchStudentRow, gradeParams, dirty, validScore, parseDecimal, allPages} from '../src/pages/grades/roles/gradeScope.js'
const own={id:1,guru_id:8,tahun_akademik_id:3,kelas_id:4,mapel_id:5},peer={...own,id:2,guru_id:9},old={...own,id:3,tahun_akademik_id:2}
const c=(id,s)=>({id,jadwal_pelajaran_id:s.id,jadwal_pelajaran:s})
const g=(id,component,student,value=85)=>({id,komponen_nilai_id:component.id,komponen_nilai:{id:component.id,jadwal_pelajaran:component.jadwal_pelajaran},siswa_id:student,nilai:value})
const student={id:7,nama_lengkap:'A'},second={id:8,nama_lengkap:'B'},component=c(11,own)
const member=(id,s=student,extra={})=>({id,kelas_id:4,siswa_id:s.id,siswa:s,tanggal_mulai:'2026-07-01',tanggal_selesai:null,...extra})
const page=(data,current_page=1,last_page=1)=>({data,meta:{current_page,last_page}})
test('grade schedules and components reject other teachers even with identical class and subject',()=>{
 assert.deepEqual(ownSchedules([own,peer,old],8,3),[own]);assert.deepEqual(ownComponents([component,c(12,peer),c(13,old),{...c(14,own),jadwal_pelajaran:peer},{id:15,jadwal_pelajaran_id:1}],own),[component])
})
test('exact grade context rejects wrong ids, period, teacher and outside roster',()=>{
 const good=g(1,component,7);const wrong=g(2,c(11,peer),7);const oldGrade=g(3,c(11,old),7)
 assert.deepEqual(scopedGrades([good,wrong,oldGrade,g(4,component,99),g(5,c(12,own),7)],11,[student],own),[good])
})
test('membership respects start and inclusive end date and exact class and student',()=>{
 const date='2026-11-22';const members=[member(1),member(2,second,{tanggal_selesai:date}),member(3,{id:9},{tanggal_mulai:'2026-11-23'}),member(4,{id:10},{tanggal_selesai:'2026-11-21'}),member(5,{id:11},{kelas_id:5}),member(6,{id:12},{siswa_id:99}),member(7,{id:13},{tanggal_mulai:null})]
 assert.deepEqual(activeStudents(members,4,date),[student,second])
})
test('zero is stored and blank is distinct; score range and decimal separator',()=>{
 assert.ok(validScore('0'));assert.ok(validScore('100'));assert.ok(validScore('85,5'));assert.equal(parseDecimal('85,5'),85.5)
 for(const v of ['', '-1','101','NaN','Infinity','85abc'])assert.equal(validScore(v),false)
 const rows=buildRows([student,second],[g(1,component,7,0)],11,own);assert.equal(rows[0].nilai,'0');assert.equal(rows[1].nilai,'');assert.equal(rows[0].gradeId,1);assert.equal(rows[1].gradeId,null)
})
test('duplicate grades fail closed; changing one row preserves other drafts',()=>{
 assert.throws(()=>buildRows([student],[g(1,component,7),g(2,component,7)],11,own),/ganda/)
 const rows=buildRows([student,second],[g(1,component,7)],11,own)
 const drafts=patchStudentRow(patchStudentRow(rows,8,{nilai:'93'}),7,{nilai:'90',original:{nilai:'90',catatan:''}})
 assert.equal(dirty(drafts[0]),false);assert.equal(drafts[1].nilai,'93');assert.ok(dirty(drafts[1]));assert.equal(rows[1].nilai,'')
})
test('pagination uses strict shared helper and teaching context cannot be overridden',async()=>{
 const calls=[];const rows=await allPages(async p=>{calls.push(gradeParams(3,1,{page:p,scope:'homeroom',academic_year_id:2,schedule_id:2}));return page([{id:p}],p,2)})
 assert.deepEqual(rows,[{id:1},{id:2}]);assert.ok(calls.every(c=>c.scope==='teaching'&&c.academic_year_id===3&&c.schedule_id===1));await assert.rejects(allPages(async()=>({data:null})))
})
// Use real HTTP client and service code; adapt Vite env/imports only for Node.
const dataModule = text => 'data:text/javascript;base64,'+Buffer.from(text).toString('base64')
async function service(name){
 const dir=new URL('../src/services/',import.meta.url)
 const error=dataModule(await readFile(new URL('apiError.js',dir),'utf8'))
 const client=dataModule((await readFile(new URL('apiClient.js',dir),'utf8')).replace(/(['"])\.\/apiError\1/,JSON.stringify(error)).replaceAll('import.meta.env.','import.meta.env?.'))
 return import(dataModule((await readFile(new URL(name+'.js',dir),'utf8')).replace(/(['"])\.\/apiClient\1/,JSON.stringify(client)).replaceAll('import.meta.env.','import.meta.env?.')))
}
test('real grade API sends teaching context, create/update and handles protected deletion',async()=>{
 const calls=[];const restore={fetch:globalThis.fetch,localStorage:globalThis.localStorage}
 globalThis.localStorage={getItem:()=> 'test-token'}
 globalThis.fetch=async(url,options)=>{calls.push({url,options});const fail=options.method==='DELETE';return new Response(JSON.stringify(fail?{message:'Komponen masih digunakan'}:page([])),{status:fail?409:200,headers:{'content-type':'application/json'}})}
 try {
  const {gradeApi}=await service('gradeApi');const {classMemberApi}=await service('classMemberApi')
  await gradeApi.getComponents(gradeParams(3,1,{page:2}));await gradeApi.getGrades(gradeParams(3,1,{component_id:11,student_id:7}));await classMemberApi.getAll(4,{page:2});await gradeApi.createGrade({komponen_nilai_id:11,siswa_id:7,nilai:0});await gradeApi.updateGrade(7,{nilai:100});await assert.rejects(gradeApi.deleteComponent(11),e=>e.status===409)
  assert.match(calls[0].url,/scope=teaching/);assert.match(calls[1].url,/component_id=11/);assert.match(calls[1].url,/student_id=7/);assert.match(calls[2].url,/classes\/4\/students/);assert.equal(JSON.parse(calls[3].options.body).nilai,0);assert.equal(calls[4].options.method,'PUT');assert.ok(calls.every(c=>c.options.headers.Authorization==='Bearer test-token'))
 } finally {globalThis.fetch=restore.fetch; if(restore.localStorage===undefined)delete globalThis.localStorage;else globalThis.localStorage=restore.localStorage}
})

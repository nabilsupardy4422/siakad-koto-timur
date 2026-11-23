import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { memberRows, loadClassMembers } from '../src/pages/class-members/roles/classMemberScope.js'
import { loadHomeroomContext,currentDay } from '../src/pages/homeroom/homeroomScope.js'
const year={id:3,is_active:true,semester:'GENAP'}, old={...year,id:2,is_active:false}, today='2026-11-22'
const a={id:1,guru_id:8,kelas_id:4,tahun_akademik_id:3,kelas:{id:4},tahun_akademik:{id:3},tanggal_mulai:'2000-01-01',tanggal_selesai:null}
const m={id:5,kelas_id:4,siswa_id:7,siswa:{id:7,nama_lengkap:'Siswa A',nis:'007',nisn:'0007'},tanggal_mulai:'2026-01-01',tanggal_selesai:null}
const list=(data,as_of=today,page=1,last=1)=>({data,meta:{as_of,current_page:page,last_page:last}})
test('inclusive ending date and future membership classify without deleting history',()=>{
 const rows=memberRows([m,{...m,id:6,siswa_id:8,siswa:{id:8},tanggal_selesai:today},{...m,id:7,siswa_id:9,siswa:{id:9},tanggal_selesai:'2026-11-21'},{...m,id:8,siswa_id:10,siswa:{id:10},tanggal_mulai:'2026-11-23'}],4,today)
 assert.deepEqual(Object.fromEntries(rows.map(r=>[r.id,r.status])),{5:'active',6:'active',7:'history',8:'scheduled'})
 assert.equal(memberRows([m],4,today)[0].nis,'007')
})
test('wrong class, student identity, invalid dates, duplicate active membership fail closed',()=>{
 for(const row of [{...m,kelas_id:9},{...m,siswa:{id:8}},{...m,tanggal_mulai:'2026-02-30'},{...m,tanggal_selesai:'2025-12-31'}])assert.throws(()=>memberRows([row],4,today))
 assert.throws(()=>memberRows([m,{...m,id:6}],4,today),/ganda/)
})
test('list pagination uses homeroom all-membership scope and server reference date',async()=>{
 const calls=[];const api={getAll:async(id,p)=>{calls.push([id,p]);return list(p.page===1?[m]:[{...m,id:6,siswa_id:8,siswa:{id:8}}],today,p.page,2)}}
 const loaded=await loadClassMembers(api,a,year,8);assert.equal(loaded.rows.length,2);assert.equal(loaded.asOf,today)
 assert.deepEqual(calls.map(([id,p])=>[id,p.page,p.scope,p.academic_year_id,p.membership_status]),[[4,1,'homeroom',3,'all'],[4,2,'homeroom',3,'all']])
})
test('inactive period, wrong owner, revoked assignment, mixed dates or API denial never return usable rows',async()=>{
 let calls=0;const api={getAll:async()=>{calls++;return list([m])}}
 await assert.rejects(loadClassMembers(api,a,{...year,is_active:false},8));await assert.rejects(loadClassMembers(api,a,year,9));await assert.rejects(loadClassMembers(api,{...a,tanggal_selesai:today},year,8));assert.equal(calls,0)
 await assert.rejects(loadClassMembers({getAll:async(_,p)=>list([m],p.page===1?today:'2026-11-23',p.page,2)},a,year,8),/Tanggal acuan/)
 await assert.rejects(loadClassMembers({getAll:async()=>{throw new Error('Forbidden')}},a,year,8),/Forbidden/)
})
test('period rollover removes previous assignment even if still open, new assignment restores context',async()=>{
 let periods=[{...old,is_active:true},{...year,is_active:false}], assignments=[{...a,tahun_akademik_id:2,tahun_akademik:{id:2}}]
 const api={academicYearApi:{getAll:async()=>list(periods)},homeroomApi:{getAll:async()=>list(assignments)}}
 assert.equal((await loadHomeroomContext(api,8)).assignments.length,1)
 periods=[old,year];assert.deepEqual((await loadHomeroomContext(api,8)).assignments,[])
 assignments.push(a);assert.deepEqual((await loadHomeroomContext(api,8)).assignments,[a])
})
const moduleUrl=source=>'data:text/javascript;base64,'+Buffer.from(source).toString('base64')
test('real member service uses read-only authenticated GET and exact scope',async()=>{
 const dir=new URL('../src/services/',import.meta.url)
 const err=moduleUrl(await readFile(new URL('apiError.js',dir),'utf8'))
 const client=moduleUrl((await readFile(new URL('apiClient.js',dir),'utf8')).replace(/(['"])\.\/apiError\1/,JSON.stringify(err)).replaceAll('import.meta.env.','import.meta.env?.'))
 const {classMemberApi}=await import(moduleUrl((await readFile(new URL('classMemberApi.js',dir),'utf8')).replace(/(['"])\.\/apiClient\1/,JSON.stringify(client))))
 const originalFetch=globalThis.fetch, originalStorage=globalThis.localStorage;let calls=[]
 globalThis.localStorage={getItem:()=> 'test-token'};globalThis.fetch=async(url,options)=>{calls.push([new URL(url),options]);return new Response(JSON.stringify(list([m])),{headers:{'content-type':'application/json'}})}
 try{assert.equal((await loadClassMembers(classMemberApi,a,year,8)).rows.length,1);assert.equal(calls.length,1);const [url,options]=calls[0];assert.ok(url.pathname.endsWith('/classes/4/students'));assert.equal(options.method,'GET');assert.equal(options.headers.Authorization,'Bearer test-token');assert.equal(url.searchParams.get('scope'),'homeroom');assert.equal(url.searchParams.get('membership_status'),'all');assert.equal(url.searchParams.get('academic_year_id'),'3')}
 finally{globalThis.fetch=originalFetch;if(originalStorage===undefined)delete globalThis.localStorage;else globalThis.localStorage=originalStorage}
})

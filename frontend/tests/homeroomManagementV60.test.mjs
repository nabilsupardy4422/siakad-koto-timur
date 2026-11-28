import test from 'node:test'
import assert from 'node:assert/strict'
import {workspaceData,filterAssignments,paginate,classLabel,dateLabel,accountLabels} from '../src/pages/homeroom/homeroomManagementLogic.js'
const record=(id,kelas_id,nama,status)=>({record:{id,kelas_id,version:'a'.repeat(64),guru:{nama_lengkap:nama,nip:'000'+id},kelas:{nama:'F1'}},status})
const rows=[record(1,10,'Ani','effective'),record(2,11,'Budi','scheduled'),record(3,10,'Cici','history'),record(4,12,'Dedi','conflict')]
const fixture=()=>({period_id:4,as_of:'2026-11-27',active_state:'inactive',assignments:rows,classes:[{id:10,status:'effective',assignment_ids:[1]},{id:11,status:'scheduled',assignment_ids:[2]},{id:12,status:'conflict',assignment_ids:[4]},{id:13,status:'unassigned',assignment_ids:[]}],summary:{total:4,effective:1,scheduled:1,conflict:1,unassigned:1}})
const filter=(patch={})=>filterAssignments(rows,{tab:'current',classId:'',status:'',search:'',...patch})
test('valid administrative snapshot for nonactive period does not imply access',()=>assert.equal(workspaceData({data:fixture()},'4').active_state,'inactive'))
test('reject wrong period, missing dates, inconsistent summary',()=>{assert.throws(()=>workspaceData({data:fixture()},5));assert.throws(()=>workspaceData({data:{...fixture(),as_of:null}},4));assert.throws(()=>workspaceData({data:{...fixture(),summary:{total:9}}},4))})
test('reject missing assignment reference and duplicate class identity',()=>{const d=fixture();d.classes[0].assignment_ids=[999];assert.throws(()=>workspaceData({data:d},4));const e=fixture();e.classes[1].id=10;assert.throws(()=>workspaceData({data:e},4))})
test('current and history tabs preserve conflicting rows',()=>{assert.deepEqual(filter().map(r=>r.record.id),[1,2,4]);assert.deepEqual(filter({tab:'history'}).map(r=>r.record.id),[3,4])})
test('filter by exact class ID, status, name and leading-zero NIP before pagination',()=>{assert.deepEqual(filter({classId:'10',search:'ANI'}).map(r=>r.record.id),[1]);assert.deepEqual(filter({search:'0002',status:'scheduled'}).map(r=>r.record.id),[2]);assert.equal(filter({classId:'11',search:'Ani'}).length,0)})
test('pagination clamps stale pages and empty state',()=>{assert.deepEqual(paginate([],7),{current:1,last:1,total:0,rows:[]});assert.deepEqual(paginate(rows,9,2).rows,rows.slice(2));assert.equal(paginate(rows,1,2).last,2)})
test('class level and date labels retain distinct classes and account states',()=>{assert.notEqual(classLabel({nama:'F1',tingkat:11}),classLabel({nama:'F1',tingkat:12}));assert.equal(dateLabel('2026-11-27'),'27/11/2026');assert.equal(accountLabels.pending,'Belum aktivasi')})

import {currentDay} from '../src/pages/homeroom/homeroomScope.js'
test('WIB date rolls over at 17:00 UTC, not midnight UTC',()=>{assert.equal(currentDay(new Date('2026-11-26T16:59:59Z')),'2026-11-26');assert.equal(currentDay(new Date('2026-11-26T17:00:00Z')),'2026-11-27')})

import {readFile} from 'node:fs/promises'
const dataModule = text => 'data:text/javascript;base64,'+Buffer.from(text).toString('base64')
test('real service sends exact authenticated workspace, candidate, replace and end requests',async()=>{
 const dir=new URL('../src/services/',import.meta.url)
 const err=dataModule(await readFile(new URL('apiError.js',dir),'utf8'))
 const client=dataModule((await readFile(new URL('apiClient.js',dir),'utf8')).replace(/(['"])\.\/apiError\1/,JSON.stringify(err)).replaceAll('import.meta.env.','import.meta.env?.'))
 const {homeroomApi}=await import(dataModule((await readFile(new URL('homeroomApi.js',dir),'utf8')).replace(/(['"])\.\/apiClient\1/,JSON.stringify(client))))
 const originalFetch=globalThis.fetch,originalStorage=globalThis.localStorage,calls=[]
 globalThis.localStorage={getItem:()=> 'test-token'}
 globalThis.fetch=async(url,options)=>{calls.push({url:new URL(url),options});return new Response(JSON.stringify({data:[]}),{headers:{'content-type':'application/json'}})}
 try{
   await homeroomApi.workspace(4)
   await homeroomApi.candidates({class_id:10,tanggal_mulai:'2026-11-27',replace_id:1})
   const body={guru_id:9,version:'a'.repeat(64),as_of:'2026-11-27'}
   await homeroomApi.replace(1,body);await homeroomApi.end(1,body.version,body.as_of)
   assert.deepEqual(calls.map(c=>c.options.method),['GET','GET','POST','DELETE'])
   assert.ok(calls.every(c=>c.options.headers.Authorization==='Bearer test-token'))
   assert.equal(calls[0].url.searchParams.get('academic_year_id'),'4');assert.equal(calls[1].url.searchParams.get('replace_id'),'1')
   assert.ok(calls[2].url.pathname.endsWith('/homeroom-assignments/1/replace'));assert.deepEqual(JSON.parse(calls[2].options.body),body)
   assert.deepEqual(JSON.parse(calls[3].options.body),{version:body.version,as_of:body.as_of})
 }finally{globalThis.fetch=originalFetch;globalThis.localStorage=originalStorage}
})

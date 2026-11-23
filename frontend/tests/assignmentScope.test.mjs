import test from 'node:test'
import assert from 'node:assert/strict'
import {readFile} from 'node:fs/promises'
import {ownAssignments, ownComponents, loadAssignmentWorkspace, deadlineToUtc, deadlineToWib, submissionRows} from '../src/pages/assignments/roles/assignmentScope.js'
const own={id:1,guru_id:8,tahun_akademik_id:3}, peer={id:2,guru_id:9,tahun_akademik_id:3}, old={id:3,guru_id:8,tahun_akademik_id:2}
const task=(id,s)=>({id,jadwal_pelajaran_id:s.id,jadwal_pelajaran:s})
const page=(data,current_page=1,last_page=1)=>({data,meta:{current_page,last_page}})
test('same class and subject do not confer assignment ownership',()=>{
 assert.deepEqual(ownAssignments([task(1,own),task(2,peer),task(3,old),{...task(4,own),jadwal_pelajaran:peer}],[own],8,3),[task(1,own)])
})
test('workspace requests teaching scope and all pages of tasks',async()=>{
 const requests=[]
 const data=await loadAssignmentWorkspace({getAll:async p=>{requests.push(p);return page(p.page===1?[task(1,own),task(2,peer)]:[task(3,own)],p.page,2)}},{getAll:async p=>{assert.equal(p.scope,'teaching');assert.equal(p.teacher_id,8);return page([own,peer,old])}},8,3)
 assert.deepEqual(data.schedules,[own]);assert.deepEqual(data.assignments.map(a=>a.id),[1,3]);assert.deepEqual(requests.map(p=>p.page),[1,2]);assert.equal(requests[0].academic_year_id,3)
})
test('missing identity and failed requests do not return workspace',async()=>{
 await assert.rejects(loadAssignmentWorkspace({}, {}, null,3));await assert.rejects(loadAssignmentWorkspace({getAll:async()=>{throw Error('network')}},{getAll:async()=>page([own])},8,3))
})
test('grade component must have exact schedule id, not just name',()=>{
 assert.deepEqual(ownComponents([{id:1,nama:'Tugas',jadwal_pelajaran_id:1},{id:2,nama:'Tugas',jadwal_pelajaran_id:2},{id:3,nama:'Tugas'},{id:4,jadwal_pelajaran_id:1,jadwal_pelajaran:{id:2}}],1).map(c=>c.id),[1])
})
test('WIB input converts to UTC and round trips independent of device timezone',()=>{
 assert.equal(deadlineToUtc('2027-01-11T07:00'),'2027-01-11 00:00:00');assert.equal(deadlineToUtc('2027-01-11T00:30'),'2027-01-10 17:30:00');assert.equal(deadlineToWib('2027-01-10T17:30:00Z'),'2027-01-11T00:30');assert.equal(deadlineToUtc('2027-02-30T07:00'),null)
})
test('submission response rejects wrong assignment rather than displaying peer data',()=>{
 assert.deepEqual(submissionRows(page([{id:1,assignment_id:5}]),5),[{id:1,assignment_id:5}]);assert.throws(()=>submissionRows(page([{id:1,assignment_id:6}]),5));assert.throws(()=>submissionRows({data:null},5))
})
// Execute the real service and apiClient source. Only adapt Vite env and extensionless imports
// for Node; mock HTTP/browser APIs, not the service functions.
const dataModule = text => 'data:text/javascript;base64,'+Buffer.from(text).toString('base64')
async function service(name){
 const dir=new URL('../src/services/',import.meta.url)
 const error=dataModule(await readFile(new URL('apiError.js',dir),'utf8'))
 const client=dataModule((await readFile(new URL('apiClient.js',dir),'utf8')).replace(/(['"])\.\/apiError\1/,JSON.stringify(error)).replaceAll('import.meta.env.','import.meta.env?.'))
 return import(dataModule((await readFile(new URL(name+'.js',dir),'utf8')).replace(/(['"])\.\/apiClient\1/,JSON.stringify(client)).replaceAll('import.meta.env.','import.meta.env?.')))
}
test('real assignment API and apiClient send correct requests, auth and handle forbidden file',async()=>{
 const calls=[];const restore={fetch:globalThis.fetch,localStorage:globalThis.localStorage}
 globalThis.localStorage={getItem:()=> 'test-token'}
 globalThis.fetch=async(url,options)=>{calls.push({url,options});return new Response(JSON.stringify(url.includes('attachments')?{message:'Dilarang'}:page([])),{status:url.includes('attachments')?403:200,headers:{'content-type':'application/json'}})}
 try {
  const {assignmentApi}=await service('assignmentApi');const {scheduleApi}=await service('scheduleApi');const {gradeApi}=await service('gradeApi')
  await loadAssignmentWorkspace(assignmentApi,scheduleApi,8,3)
  await gradeApi.getComponents({schedule_id:1,page:2})
  await assignmentApi.getSubmissions(5,{page:2});await assignmentApi.create({judul:'Test'});await assignmentApi.update(5,{judul:'Edit'})
  await assert.rejects(assignmentApi.downloadAssignmentAttachment(9));await assert.rejects(assignmentApi.downloadSubmissionAttachment(10));await assert.rejects(assignmentApi.viewSubmissionAttachment(10))
  assert.match(calls[0].url,/scope=teaching/);assert.match(calls[0].url,/teacher_id=8/);assert.match(calls[1].url,/assignments\?academic_year_id=3/);assert.match(calls[2].url,/grade-components\?schedule_id=1&page=2/);assert.match(calls[3].url,/assignments\/5\/submissions\?page=2/);assert.equal(calls[4].options.method,'POST');assert.equal(calls[5].options.method,'PUT');assert.match(calls[6].url,/assignment-attachments\/9\/download/);assert.match(calls[7].url,/submission-attachments\/10\/download/);assert.match(calls[8].url,/submission-attachments\/10\/view/);assert.ok(calls.every(c=>c.options.headers.Authorization==='Bearer test-token'))
 } finally {globalThis.fetch=restore.fetch; if(restore.localStorage===undefined)delete globalThis.localStorage;else globalThis.localStorage=restore.localStorage}
})

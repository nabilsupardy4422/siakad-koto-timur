import test from 'node:test'
import assert from 'node:assert/strict'
import { ownMaterials, loadMaterialWorkspace, canSelectMaterialSchedule } from '../src/pages/materials/roles/materialScope.js'
const own = {id: 1, guru_id: 8, tahun_akademik_id: 3, kelas_id: 1, mapel_id: 1}
const peer = {...own, id: 2, guru_id: 9}
const old = {...own, id: 3, tahun_akademik_id: 2}
const row = (id, schedule) => ({id, judul:'Materi', jadwal_pelajaran_id:schedule.id, jadwal_pelajaran:schedule})
const mine = row(10,own), other = row(11,peer), historical = row(12,old)
const response = (data, page=1, last=1) => ({data, meta:{current_page:page,last_page:last}})

test('same class and subject do not confer ownership', () => {
  assert.deepEqual(ownMaterials([mine,other,historical], [own,peer,old],8,3), [mine])
  assert.deepEqual(ownMaterials([mine,other,historical], [own,peer,old],8,2), [historical])
})
test('inconsistent schedule ids, period, owner or missing assignment are rejected', () => {
  const badRows = [{...mine,jadwal_pelajaran_id: 2}, {...mine,jadwal_pelajaran:{...own,guru_id:9}}, {...mine,jadwal_pelajaran:{...own,tahun_akademik_id:2}}, {...mine,jadwal_pelajaran:undefined}, {judul:'no id',jadwal_pelajaran:own}]
  assert.deepEqual(ownMaterials(badRows,[own],8,3), [])
  assert.deepEqual(ownMaterials([mine],[],8,3), [])
})
test('workspace loads every page with matching teaching and year scope', async () => {
  const schCalls=[], matCalls=[]
  const result=await loadMaterialWorkspace({getAll: async params=>{schCalls.push(params);return params.page===1?response([peer],1,2):response([own],2,2)}},{getAll:async params=>{matCalls.push(params);return params.page===1?response([other],1,2):response([mine],2,2)}},8,3)
  assert.deepEqual(result,{schedules:[own],materials:[mine]})
  assert.deepEqual(schCalls,[1,2].map(page=>({scope:'teaching',academic_year_id:3,per_page:100,teacher_id:8,history:1,page})))
  assert.deepEqual(matCalls,[1,2].map(page=>({scope:'teaching',academic_year_id:3,per_page:100,page})))
})
test('historical material is matched to its historical schedule, not active-period names', async () => {
  const result=await loadMaterialWorkspace({getAll:async p=>{assert.equal(p.academic_year_id,2);return response([own,old,peer])}},{getAll:async p=>{assert.equal(p.academic_year_id,2);return response([mine,historical])}},8,2)
  assert.deepEqual(result,{schedules:[old],materials:[historical]})
})
test('missing identity, missing period and API failure do not return a usable workspace',async()=>{
  let calls=0;const api={getAll:async()=>{calls++;throw Error('network')}}
  await assert.rejects(loadMaterialWorkspace(api,api,null,3));await assert.rejects(loadMaterialWorkspace(api,api,8,''));assert.equal(calls,0)
  await assert.rejects(loadMaterialWorkspace(api,api,8,3),/network/)
  await assert.rejects(loadMaterialWorkspace({getAll:async()=>({data:null})},{getAll:async()=>response([])},8,3),/Format daftar/)
})
test('form only accepts selected assignment from same teacher and period',()=>{
  assert.equal(canSelectMaterialSchedule([own,peer,old],'1',8,3),true)
  for(const id of [2,3,4,null]) assert.equal(canSelectMaterialSchedule([own,peer,old],id,8,3),false)
})

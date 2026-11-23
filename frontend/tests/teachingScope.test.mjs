import test from 'node:test'
import assert from 'node:assert/strict'
import { teacherId, ownTeachingSchedules, collectPages, loadOwnTeachingSchedules } from '../src/utils/teachingScope.js'

const own = {id:1,guru_id:8,tahun_akademik_id:3}
const own2 = {id:2,guru:{id:'8'},tahun_akademik:{id:3}}
const peer = {id:3,guru_id:9,tahun_akademik_id:3}
const old = {id:4,guru_id:8,tahun_akademik_id:2}
const unknown = {id:5,tahun_akademik_id:3}

test('uses teacher profile id, not user id', () => {
  assert.equal(teacherId({id:99,guru:{id:8}}),8)
  assert.equal(teacherId({id:99}),null)
})
test('rejects records belonging to another teacher, period or missing identity', () => {
  assert.deepEqual(ownTeachingSchedules([own,peer,own2,old,unknown,null],8,3),[own,own2])
  assert.deepEqual(ownTeachingSchedules([own],null,3),[])
  assert.deepEqual(ownTeachingSchedules([own],8,null),[])
})
test('requests explicit teaching context and filters every page, even if API returns mixed data', async () => {
  const calls=[]
  const api={getAll:async params=>{calls.push(params);return {data:params.page===1?[own,peer]:[own2,old,unknown,own],meta:{last_page:2,current_page:params.page}}}}
  assert.deepEqual(await loadOwnTeachingSchedules(api,8,3),[own,own2])
  assert.deepEqual(calls,[1,2].map(page=>({scope:'teaching',teacher_id:8,academic_year_id:3,per_page:100,page})))
})
test('missing identity prevents any API call', async () => {
  await assert.rejects(loadOwnTeachingSchedules({getAll:()=>assert.fail('unexpected API request')},null,3),/Identitas Guru/)
})
test('invalid response, pagination and network errors fail closed', async () => {
  await assert.rejects(collectPages(async()=>({data:null})),/Format daftar/)
  await assert.rejects(collectPages(async()=>({data:[],meta:{last_page:0}})),/pagination/)
  await assert.rejects(collectPages(async()=>({data:[],meta:{last_page:2,current_page:2}})),/Halaman data/)
  await assert.rejects(collectPages(async()=>{throw new Error('network')}),/network/)
})

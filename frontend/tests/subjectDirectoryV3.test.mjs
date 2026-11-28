import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { subjectForm, validateSubjectForm, subjectPayload, kkmView, subjectDirectoryResponse, loadSubjectCatalog } from '../src/pages/subjects/subjectDirectoryLogic.js'
const source = path => readFileSync(new URL(path, import.meta.url), 'utf8')
test('KKM zero remains set and blank remains null', () => {
  assert.equal(kkmView(0).label, '0'); assert.equal(kkmView(null).state, 'unset'); assert.equal(kkmView('101.00').state, 'invalid')
  assert.equal(subjectForm({ kode:'A', nama:'A', kkm:0 }).kkm, '0')
  assert.equal(subjectPayload({ kode:' 001 ', nama:' A ', kkm:'' }).kkm, null)
  assert.deepEqual(subjectPayload({ kode:' 001 ', nama:' A ', kkm:'0' }), { kode:'001', nama:'A', kkm:'0' })
})
test('form rejects invalid KKM, permits decimals and optional KKM', () => {
  for (const kkm of ['-1','101','75.123','1e2','abc']) assert.ok(validateSubjectForm({kode:'A',nama:'A',kkm}).kkm)
  for (const kkm of ['','0','100','75,25','75.25']) assert.deepEqual(validateSubjectForm({kode:'A',nama:'A',kkm}), {})
  assert.equal(subjectPayload({kode:'A',nama:'A',kkm:'75,25'},'v').kkm,'75.25')
})
test('TU rejects incomplete metadata instead of inventing statistics', () => {
  const response = { data:[], meta:{ total:0, current_page:1, last_page:1, per_page:15 } }
  assert.throws(()=>subjectDirectoryResponse(response,true)); assert.equal(subjectDirectoryResponse(response,false).meta.total,0)
  response.meta.summary = {total:0,used:0,unset:0,invalid:0}
  assert.equal(subjectDirectoryResponse(response,true).meta.summary.total,0)
})
test('full catalog retrieves all pages with original envelope', async () => {
  const calls=[];const result=await loadSubjectCatalog(async page=>{calls.push(page);return {data:[{id:page}],meta:{current_page:page,last_page:3,total:3}}})
  assert.deepEqual(calls,[1,2,3]);assert.deepEqual(result.data.map(r=>r.id),[1,2,3]);assert.equal(result.meta.total,3)
})
test('full catalog rejects repeated rows and inconsistent totals', async () => {
  await assert.rejects(loadSubjectCatalog(async page=>({data:[{id:1}],meta:{current_page:page,last_page:2,total:2}})))
  await assert.rejects(loadSubjectCatalog(async page=>({data:[],meta:{current_page:page,last_page:1,total:1}})))
})
test('schedule and reports consume full subject catalog', () => {
  for(const file of ['../src/pages/schedules/roles/AdminSchedule.jsx','../src/pages/reports/components/ReportFilters.jsx']) assert.match(source(file),/subjectApi\.getCatalog\(\)/)
})
test('directory uses shared modal toast scroll and sends version', () => {
  const jsx=source('../src/pages/subjects/SubjectsPage.jsx')
  for(const term of ['WorkspaceModal','ToastNotification','HorizontalScrollControls','subject.version','subjectApi.getById','!locked']) assert.ok(jsx.includes(term))
  assert.doesNotMatch(jsx,/window\.confirm|window\.alert|Import Excel|Export Excel/)
})

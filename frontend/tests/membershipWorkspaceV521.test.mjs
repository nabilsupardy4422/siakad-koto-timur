import test from 'node:test'
import assert from 'node:assert/strict'
import {transitionMode,allowedActions,destinationOptions,buildTransitionItems,previousDate,classTitle} from '../src/pages/class-members/membershipWorkspaceLogic.js'
import {placementLabel as masterPlacement} from '../src/pages/identity/identityDirectoryLogic.js'
import {placementLabel as ownPlacement} from '../src/pages/profile/personalProfileLogic.js'
const a={id:1,tahun_mulai:2026,tahun_selesai:2027,semester:'GANJIL'},b={...a,id:2,semester:'GENAP'},c={id:3,tahun_mulai:2027,tahun_selesai:2028,semester:'GANJIL'}
const origin={id:4,tingkat:10,nama:'E1',tahun_akademik_id:2},classes=[origin,{id:5,tingkat:11,nama:'F1',tahun_akademik_id:3},{id:6,tingkat:12,nama:'F1',tahun_akademik_id:3},{id:7,tingkat:10,nama:'E2',tahun_akademik_id:3}]
test('adjacent periods only and explicit same-period mode',()=>{assert.equal(transitionMode(a,b),'semester');assert.equal(transitionMode(b,c),'year');assert.equal(transitionMode(a,c),null);assert.equal(transitionMode(a,a),'same')})
test('graduate only XII and year transition',()=>{assert.deepEqual(allowedActions('year',12),['graduate','retain','withdraw']);assert.ok(!allowedActions('year',11).includes('graduate'));assert.ok(!allowedActions('semester',12).includes('graduate'))})
test('target options disambiguate duplicate names by actual level and period',()=>{assert.deepEqual(destinationOptions(classes,origin,'3','promote').map(c=>c.id),[5]);assert.deepEqual(destinationOptions(classes,origin,'3','retain').map(c=>c.id),[7]);assert.equal(classTitle(classes[1]),'XI — F1');assert.equal(classTitle(classes[2]),'XII — F1')})
test('individual decision overrides rombel mapping, terminal has no destination',()=>{const selected=[{id:10,kelas_id:4},{id:11,kelas_id:4}];assert.deepEqual(buildTransitionItems(selected,{4:{action:'promote',destination_class_id:'5'}},{11:{action:'withdraw',destination_class_id:'7'}}),[{membership_id:10,action:'promote',destination_class_id:5},{membership_id:11,action:'withdraw',destination_class_id:null}])})
test('effective date computes inclusive previous calendar day across year/leap boundary',()=>{assert.equal(previousDate('2027-01-01'),'2026-12-31');assert.equal(previousDate('2028-03-01'),'2028-02-29');assert.equal(previousDate(''),'')})
test('academic terminal status is visible separately on profile and master',()=>{assert.equal(masterPlacement({status:'historical',academic_status:'graduate'}),'Lulus');assert.equal(ownPlacement({status:'unassigned',academic_status:'withdraw'}),'Pindah keluar')})

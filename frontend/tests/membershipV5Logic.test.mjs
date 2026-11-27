import test from 'node:test'
import assert from 'node:assert/strict'
import {jakartaDate,initialClassContext,membershipStatus,canCommitPreview} from '../src/pages/class-members/membershipV5Logic.js'
const years=[{id:1,is_active:false},{id:2,is_active:true}], classes=[{id:3,tahun_akademik_id:1},{id:4,tahun_akademik_id:2}]
test('active period defaults, not first class',()=>assert.deepEqual(initialClassContext(years,classes,{}),{yearId:'2',classId:'4',missing:false}))
test('ambiguous active period never guesses',()=>assert.equal(initialClassContext(years.map(y=>({...y,is_active:true})),classes,{}).classId,''))
test('deep link binds class to exact period',()=>{assert.equal(initialClassContext(years,classes,{class_id:'3',academic_year_id:'2'}).missing,true);assert.equal(initialClassContext(years,classes,{class_id:'3',academic_year_id:'1'}).classId,'3')})
test('Jakarta dates after midnight are not UTC yesterday',()=>assert.equal(jakartaDate(new Date('2026-11-26T18:00:00Z')),'2026-11-27'))
test('membership end is inclusive and future and invalid are distinguished',()=>{const row={tanggal_mulai:'2026-10-01',tanggal_selesai:'2026-11-27'};assert.equal(membershipStatus(row,'2026-11-27'),'Efektif pada tanggal acuan');assert.equal(membershipStatus(row,'2026-11-28'),'Riwayat');assert.equal(membershipStatus(row,'2026-09-30'),'Terjadwal');assert.equal(membershipStatus({...row,tanggal_selesai:'2025-01-01'},'2026-11-27'),'Tidak valid')})
test('commit requires every row valid and signed preview and batch limit',()=>{const p={preview_token:'signed',rows:[{valid:true}]};assert.equal(canCommitPreview(p),true);for(const x of [{...p,preview_token:''},{...p,rows:[]},{...p,rows:[{valid:true},{valid:false}]},{...p,rows:Array(101).fill({valid:true})}])assert.equal(canCommitPreview(x),false)})

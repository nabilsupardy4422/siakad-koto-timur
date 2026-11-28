import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync,readdirSync} from 'node:fs'
import {mergeSelection,accountTotals,filteredSnapshot,validateBulkPreview,bulkPayload,isAcademic} from '../src/pages/users/bulkAccountLogic.js'
const row=(id,code='GURU')=>({id,role:{code}})
const preview=(action='issue',rows=[{user_id:1,eligible:true,has_active_code:false}])=>({data:{action,rows,total:rows.length,eligible:rows.filter(r=>r.eligible).length,reissue_count:action==='issue'?rows.filter(r=>r.eligible&&r.has_active_code).length:0,expires:1700000000,preview_token:'a'.repeat(64),preview_nonce:'b'.repeat(32)}})
test('selection retains exact unique numeric ids and caps at 100',()=>{
 assert.deepEqual(mergeSelection([1,2],[2,3]),[1,2,3]);assert.throws(()=>mergeSelection([],Array.from({length:101},(_,i)=>i+1)));assert.throws(()=>mergeSelection([],['1']));assert.throws(()=>mergeSelection([],[-1]));assert.equal(isAcademic(row(1,'TU')),false)
})
test('summary accepts actual zero and rejects inconsistent or missing counts',()=>{
 assert.deepEqual(accountTotals({data:{total:3,ready:1,pending:1,inactive:1,unknown:0}}),{total:3,ready:1,pending:1,inactive:1,unknown:0})
 assert.equal(accountTotals({data:{total:0,ready:0,pending:0,inactive:0,unknown:0}}).total,0);assert.equal(accountTotals({data:{total:5,ready:1,pending:1,inactive:1,unknown:0}}),null);assert.equal(accountTotals({data:{total:0,ready:0,pending:0,inactive:0}}),null)
})
test('all filtered selection requires a complete snapshot and excludes administrative roles explicitly',()=>{
 const r={data:[row(1),row(2,'TU'),row(3,'SISWA')],meta:{total:3,current_page:1,last_page:1}}
 assert.deepEqual(filteredSnapshot(r,3),{ids:[1,3],excluded:1});assert.throws(()=>filteredSnapshot(r,4));assert.throws(()=>filteredSnapshot({...r,data:[row(1),row(1),row(3)]},3));assert.throws(()=>filteredSnapshot({...r,meta:{...r.meta,last_page:2}},3))
})
test('preview rejects foreign ids, duplicate ids, wrong action and inconsistent eligibility',()=>{
 assert.equal(validateBulkPreview(preview(),'issue',[1]).eligible,1);assert.throws(()=>validateBulkPreview(preview(),'deactivate',[1]));assert.throws(()=>validateBulkPreview(preview(),'issue',[2]));assert.throws(()=>validateBulkPreview(preview('issue',[{user_id:1,eligible:true,has_active_code:false},{user_id:1,eligible:true,has_active_code:false}]),'issue',[1,2]));assert.throws(()=>validateBulkPreview({data:{...preview().data,eligible:0}},'issue',[1]))
})
test('confirmation requires exact eligible list and explicit active-code replacement',()=>{
 const p=validateBulkPreview(preview('issue',[{user_id:1,eligible:true,has_active_code:true}]),'issue',[1]);assert.throws(()=>bulkPayload(p,[1],true,false));assert.throws(()=>bulkPayload(p,[1],false,true));assert.throws(()=>bulkPayload(p,[2],true,true));assert.deepEqual(bulkPayload(p,[1],true,true),{user_ids:[1],expires:p.expires,preview_token:p.preview_token,preview_nonce:p.preview_nonce,confirm:true,confirm_reissue:true})
 const blocked=validateBulkPreview(preview('issue',[{user_id:1,eligible:false,has_active_code:false}]),'issue',[1]);assert.throws(()=>bulkPayload(blocked,[1],true,true))
})
const src=p=>readFileSync(new URL(`../src/${p}`,import.meta.url),'utf8')
test('bulk writes use authenticated API and preview; PDF is a revocable binary download only',()=>{
 const api=src('services/userApi.js'),modal=src('pages/users/BulkAccountModal.jsx'),page=src('pages/users/UsersPage.jsx');assert.match(api,/postBlob\('\/users\/bulk\/activation-codes'/);assert.match(modal,/validateBulkPreview\(r,action,ids\)/);assert.match(modal,/bulkPayload\(preview,ids,confirmed,reissue\)/);assert.match(modal,/URL\.createObjectURL\(blob\)/);assert.match(modal,/URL\.revokeObjectURL/);assert.match(modal,/operation\.current/);assert.doesNotMatch(modal,/localStorage|sessionStorage|console\.log/);assert.match(page,/\[filterKey\]/);assert.match(page,/filteredSnapshot\(response,meta\.total\)/)
})
test('new module names resolve with exact casing and no stray filename expression',()=>{
 const dir=new URL('../src/pages/users/',import.meta.url);for(const f of ['BulkAccountModal.jsx','bulkAccountLogic.js'])assert.ok(readdirSync(dir).includes(f));
 for(const f of ['users/UsersPage.jsx','users/BulkAccountModal.jsx','students/StudentsPage.jsx','teachers/TeachersPage.jsx','identity/IdentityDirectoryUI.jsx'])assert.doesNotMatch(src('pages/'+f),/^\s*[A-Za-z_$][\w$]*\.jsx\s*;?\s*$/m)
})

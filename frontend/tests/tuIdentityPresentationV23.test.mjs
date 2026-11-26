import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync} from 'node:fs'
import {identityInitials,completenessView,directoryTotals} from '../src/pages/identity/identityDirectoryPresentation.js'
const source=name=>readFileSync(new URL(`../src/pages/${name}`,import.meta.url),'utf8')
const totals=(...values)=>values.map(total=>({meta:{total}}))
test('initials preserve name identity and do not fabricate missing names',()=>{
 assert.equal(identityInitials('  Guru   Contoh  '),'GC');assert.equal(identityInitials('Éka'),'É');assert.equal(identityInitials(null),'–')
})
test('completeness distinguishes actual zero from invalid or absent values',()=>{
 assert.deepEqual(completenessView({filled:0,total:5}),{filled:0,total:5,complete:false,percent:0});assert.equal(completenessView({filled:8,total:8}).complete,true)
 for(const v of [null,{}, {filled:6,total:5},{filled:-1,total:5},{filled:'3',total:5},{filled:1,total:0},{filled:1.2,total:5}])assert.equal(completenessView(v),null)
})
test('aggregate counts use server pagination totals, not current page length',()=>{
 assert.deepEqual(directoryTotals(totals(34,4,29)),{total:34,ready:4,incomplete:29});assert.deepEqual(directoryTotals(totals(0,0,0)),{total:0,ready:0,incomplete:0});assert.equal(directoryTotals(totals(3,4,2)),null);assert.equal(directoryTotals(totals(3,'2',1)),null);assert.equal(directoryTotals([{}, {}, {}]),null)
})
test('overview uses TU gated existing endpoints and reloads after successful writes',()=>{
 for(const file of ['teachers/TeachersPage.jsx','students/StudentsPage.jsx']){const s=source(file);assert.match(s,/canManage\s*&&\s*<DirectoryOverview/);assert.match(s,/setOverviewRevision\(value\s*=>\s*value\s*\+\s*1\)/);assert.match(s,/identity-directory-advanced/)}
 const ui=source('identity/IdentityDirectoryUI.jsx');assert.match(ui,/page_size\s*:\s*1/);assert.match(ui,/account_state\s*:\s*'ready'/);assert.match(ui,/completeness\s*:\s*'incomplete'/);assert.match(ui,/directoryTotals\(responses\)/)
})
test('directory and import use scoped native workspace and keep deletion confirmation',()=>{
 for(const file of ['teachers/TeachersPage.jsx','students/StudentsPage.jsx']){const s=source(file);assert.match(s,/modalOpen\s*&&\s*<WorkspaceModal/);assert.match(s,/importModalOpen\s*&&\s*<WorkspaceModal/);assert.match(s,/busy=\{previewLoading\s*\|\|\s*importing\}/);assert.match(s,/onDelete=\{canManage\s*\?/);assert.match(s,/onClick=\{confirmDelete(?:Student)?\}/)}
})
test('student exclusions remain before identifiers and invoke server preview',()=>{
 const s=source('students/StudentsPage.jsx');assert.match(s,/<th>Baris<\/th>\s*<th>Aksi<\/th>\s*<th>NISN<\/th>/);assert.match(s,/toggleExcludedRow\(Number\(row\.row\)\)/);assert.match(s,/await handlePreviewImport\(next\)/);assert.match(s,/students-page__preview-status--excluded/)
})
test('user import offers only canonical academic templates with visible errors',()=>{
 const s=source('users/UsersPage.jsx');assert.match(s,/teacherApi\s*:\s*studentApi\)\.downloadTemplate\(\)/);assert.match(s,/template_data_guru\.xlsx/);assert.match(s,/template_data_siswa\.xlsx/);assert.match(s,/templateError\s*&&\s*<ErrorState/);assert.doesNotMatch(s,/userImportApi|Unduh template legacy|template-import-pengguna-legacy/)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync, readdirSync} from 'node:fs'
import {academicProfileRoute, placementLabel} from '../src/pages/identity/identityDirectoryLogic.js'
const source = name => readFileSync(new URL(`../src/pages/${name}`, import.meta.url),'utf8')
test('linked teacher uses profile id, unlinked active teacher uses exact user id',()=>{
 assert.equal(academicProfileRoute({id:71,role:{code:'GURU'},academic_profile:{status:'linked',id:9}}),'/app/teachers?profile_id=9')
 assert.equal(academicProfileRoute({id:71,role:{code:'GURU'},account_status:'active',academic_profile:{status:'unlinked'}}),'/app/teachers?link_user=71')
})
test('student route, inactive orphan and conflict are handled separately',()=>{
 assert.equal(academicProfileRoute({id:71,role:{code:'SISWA'},account_status:'active',academic_profile:{status:'unlinked'}}),'/app/students?link_user=71')
 assert.equal(academicProfileRoute({id:71,role:{code:'SISWA'},account_status:'inactive',academic_profile:{status:'unlinked'}}),null)
 assert.equal(academicProfileRoute({id:71,role:{code:'GURU'},account_status:'active',academic_profile:{status:'conflict'}}),null)
 assert.equal(academicProfileRoute({id:'x',role:{code:'GURU'},account_status:'active',academic_profile:{status:'unlinked'}}),null)
})
test('placement distinguishes current, history, future, unknown and conflicts',()=>{
 assert.equal(placementLabel({status:'effective',class_name:'X A'}),'X A')
 assert.equal(placementLabel({status:'historical',class_name:'X A'}),'X A (riwayat)')
 assert.equal(placementLabel({status:'unassigned'}),'Belum ditempatkan')
 assert.equal(placementLabel({status:'future'}),'Belum mulai')
 assert.equal(placementLabel({status:'conflict'}),'Konflik penempatan')
 assert.equal(placementLabel({status:'period_unavailable'}),'Periode belum tersedia')
 assert.equal(placementLabel(null),'Belum diperiksa')
})
test('normal account registration and import navigate to canonical academic form',()=>{
 const s=source('users/UsersPage.jsx')
 assert.match(s,/\?register=1/)
 assert.match(s,/\?import=1/)
 assert.match(s,/academicProfileRoute\(user\)/)
 assert.doesNotMatch(s,/<UserImportButton/)
})
test('academic forms validate link by account id, do not create an invented profile',()=>{
 for(const name of ['teachers/TeachersPage.jsx','students/StudentsPage.jsx']){
   const s=source(name)
   assert.match(s,/available\.find\(\s*(?:a|\(\s*a\s*\))\s*=>\s*String\(\s*a\.id\s*\)\s*===\s*requestedId\s*,?\s*\)/)
   assert.match(s,/registration_mode\s*:\s*(['"])existing_account\1/)
   assert.match(s,/navigate\(\s*`\/app\/users\?user_id=/)
   assert.match(s,/ToastNotification/)
 }
})
test('student preview exclusions are sent to backend and require server preview token',()=>{
 const s=source('students/StudentsPage.jsx')
 assert.match(s,/exclusions\.forEach\(\s*(?:row|\(\s*row\s*\))\s*=>\s*(?:\{\s*)?formData\.append\(\s*(['"])excluded_rows\[\]\1\s*,\s*String\(\s*row\s*\)\s*,?\s*\)/)
 assert.match(s,/formData\.append\(\s*(['"])preview_token\1\s*,\s*previewData\.preview_token\s*,?\s*\)/)
 assert.match(s,/const\s+previewSummary\s*=\s*previewData\?\.summary/)
 assert.match(s,/if\s*\(\s*!selectedExcel\s*\|\|\s*!previewData\?\.preview_token\s*\)\s*(?:\{\s*)?return\b/)
 assert.doesNotMatch(s,/const\s+duplicateError\b/)
})

// Regression: validate the actual relative import and exact filename casing.
test('identity directory consumers resolve the exact shared JS module', () => {
 const dir = new URL('../src/pages/identity/', import.meta.url)
 for (const filename of ['identityDirectoryLogic.js','IdentityDirectoryDetail.jsx','IdentityDirectory.css']) {
   assert.ok(readdirSync(dir).includes(filename), filename)
 }
 for (const name of ['teachers/TeachersPage.jsx','students/StudentsPage.jsx']) {
   const paths = [...source(name).matchAll(/from\s+['"]([^'"]*identityDirectoryDetail[^'"]*)['"]/gi)]
   assert.equal(paths.length, 1, name)
   assert.equal(paths[0][1], '../identity/IdentityDirectoryDetail.jsx', name)
 }
 const cssPaths = [...source('identity/IdentityDirectoryDetail.jsx').matchAll(/import\s+['"]([^'"]*identityDirectory\.css)['"]/gi)]
 assert.equal(cssPaths.length, 1)
 assert.equal(cssPaths[0][1], './IdentityDirectory.css')
 for (const name of ['identity/IdentityDirectoryDetail.jsx','students/StudentsPage.jsx','users/UsersPage.jsx']) {
   const matches = [...source(name).matchAll(/from\s+['"]([^'"]*identityDirectoryLogic[^'"]*)['"]/gi)]
   assert.equal(matches.length, 1, name)
   const expected = name.startsWith('identity/') ? './identityDirectoryLogic.js' : '../identity/identityDirectoryLogic.js'
   assert.equal(matches[0][1], expected, name)
   const resolved = new URL(matches[0][1], new URL(`../src/pages/${name}`, import.meta.url))
   assert.equal(resolved.href, new URL('identityDirectoryLogic.js', dir).href)
   assert.ok(readFileSync(resolved, 'utf8').length > 0)
 }
 assert.equal(typeof placementLabel, 'function')
 assert.equal(typeof academicProfileRoute, 'function')
})

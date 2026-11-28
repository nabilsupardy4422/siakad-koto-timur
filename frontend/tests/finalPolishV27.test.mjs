import test from 'node:test'
import assert from 'node:assert/strict'
import {readFileSync, readdirSync} from 'node:fs'
import {formatIdentityDate} from '../src/utils/identityDate.js'
const src = file => readFileSync(new URL(`../src/${file}`, import.meta.url), 'utf8')
test('all 21 route page modules are lazy and match case-sensitive source paths', () => {
 const app=src('App.jsx'); const matches=[...app.matchAll(/const (\w+) = lazy\(\(\) => import\("(\.\/pages\/[^\"]+)"\)\);/g)]
 assert.equal(matches.length,21); assert.doesNotMatch(app,/^import \w+ from "\.\/pages\//m)
 for(const [,name,path] of matches){let dir=new URL('../src/',import.meta.url);for(const part of path.slice(2).split('/')){assert.ok(readdirSync(dir).includes(part),path);dir=new URL(part+'/',dir)}assert.ok(app.includes(`<${name} />`),name)}
 assert.match(app,/<RequireAuth\s*\/>/);assert.match(app,/assignment="WALI_KELAS"/)
})
test('route loading and error boundaries preserve the shell and reset only on pathname',()=>{
 assert.match(src('app/AppShell.jsx'),/<RouteContentBoundary><Outlet \/><\/RouteContentBoundary>/)
 const b=src('routes/RouteContentBoundary.jsx'); assert.match(b,/<Suspense fallback=/);assert.match(b,/key=\{pathname\}/);assert.match(b,/getDerivedStateFromError/);assert.match(b,/window\.location\.reload\(\)/)
 assert.equal((src('App.jsx').match(/<RouteContentBoundary publicPage>/g)||[]).length,3)
 assert.doesNotMatch(src('main.jsx'),/Landing\.css/);assert.match(src('pages/public/LandingPage.jsx'),/Landing\.css/)
})
test('official calendar dates use Indonesian date formatting without timezone shift',()=>{
 assert.equal(formatIdentityDate('2009-04-12'),'12 April 2009');assert.equal(formatIdentityDate('2026-11-27T23:00:00-08:00'),'27 November 2026');assert.equal(formatIdentityDate('2024-02-29'),'29 Februari 2024');assert.equal(formatIdentityDate('2026-02-29'),'Tanggal tidak valid');assert.equal(formatIdentityDate(null),null);assert.equal(formatIdentityDate(undefined),undefined);assert.equal(formatIdentityDate(''),'');assert.equal(formatIdentityDate('bogus'),'Tanggal tidak valid')
 assert.match(src('pages/identity/IdentityDirectoryDetail.jsx'),/formatIdentityDate\(profile\.tanggal_lahir\)/);assert.match(src('pages/profile/PersonalProfileWorkspace.jsx'),/formatIdentityDate\(s\)/)
})
test('Google link disclosure retains current password and guards hidden widget',()=>{
 const p=src('pages/profile/ProfilePage.jsx');assert.match(p,/aria-expanded=\{linkOpen\}/);assert.match(p,/firstLink && \(!linkOpen \|\| !linkPasswordReady\)/);assert.match(p,/googleLinked === false && linkOpen && linkPasswordReady/);assert.match(p,/current_password: linkPassword/);assert.match(p,/setLinkOpen\(false\)/);assert.match(p,/siakad-profile__verify/)
})
test('both Excel previews have a keyboard-focusable dedicated horizontal scroll region',()=>{
 const ui=src('pages/identity/IdentityDirectoryUI.jsx');assert.match(ui,/HorizontalScrollControls scrollRef=\{scrollRef\}/);assert.match(ui,/ref=\{scrollRef\} className=\{className\} tabIndex=\{0\} role="region"/)
 for(const [kind,page] of [['teachers','TeachersPage'],['students','StudentsPage']]){const text=src(`pages/${kind}/${page}.jsx`);assert.match(text,new RegExp(`<ImportPreviewScroll className="${kind}-page__preview-table-wrapper"`));assert.match(text,/<\/ImportPreviewScroll>/)}
})
test('breadcrumb labels, modal focus restore, and mobile toast controls remain accessible',()=>{
 assert.match(src('app/Breadcrumb.jsx'),/\x27\/app\/profil\x27: \x27Profil Saya\x27/);assert.match(src('app/Breadcrumb.jsx'),/\x27\/app\/homeroom-assignments\x27:/);assert.match(src('components/ui/WorkspaceModal.jsx'),/prior\.focus\(\{preventScroll:true\}\)/);assert.match(src('components/ui/WorkspaceModal.jsx'),/onCancel=/);assert.match(src('components/ui/ToastNotification.css'),/safe-area-inset-bottom/);assert.match(src('components/ui/ToastNotification.css'),/min-height:44px/);assert.match(src('app/AppShell.css'),/main:focus-visible/)
})

test('bundle measurement counts only static entry imports and deduplicates shared assets', async()=>{
 const {measureManifest}=await import('../tools/measureInitialBundle.mjs')
 const result=measureManifest({entry:{isEntry:true,file:'entry.js',imports:['shared'],dynamicImports:['page'],css:['main.css']},shared:{file:'shared.js',css:['main.css']},page:{file:'page.js',isDynamicEntry:true,imports:['shared'],css:['page.css']}},file=>Buffer.alloc({'entry.js':10,'shared.js':20,'page.js':50,'main.css':12,'page.css':13}[file]))
 assert.equal(result.initial.js.bytes,30);assert.equal(result.initial.css.bytes,12);assert.equal(result.total.js.bytes,80);assert.equal(result.total.css.bytes,25);assert.equal(result.dynamicEntries,1);assert.equal(result.largestJavaScript.file,'page.js');assert.equal(result.initialFiles.length,3)
})

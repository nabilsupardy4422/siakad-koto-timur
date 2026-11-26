import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { nextHorizontalPosition, horizontalScrollEdges } from '../src/components/ui/horizontalScrollLogic.js'
const source = path => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const table = source('src/components/ui/Table.jsx')
const controls = source('src/components/ui/HorizontalScrollControls.jsx')
const css = source('src/pages/identity/IdentityDirectory.css')

for (const [file,label] of [['teachers/TeachersPage.jsx','Tabel Data Guru'],['students/StudentsPage.jsx','Tabel Data Siswa'],['users/UsersPage.jsx','Tabel Manajemen Pengguna']]) {
  test(`${label} opts into scoped scrolling and preserves action column`, () => {
    const page = source(`src/pages/${file}`)
    assert.match(page, new RegExp(`<Table\\s+scrollLabel=["']${label}["']`))
    assert.match(page, /key:\s*['"]actions['"],\s*label:\s*['"]Aksi['"]/)
    assert.match(page, /case\s+['"]actions['"]:/)
  })
}

test('guided tables share the actual ref above and below, legacy tables remain unchanged', () => {
  assert.match(table, /ref=\{scrollRef\}/)
  assert.match(table, /role=\{scrollLabel\s*\?\s*'region'/)
  assert.match(table, /tabIndex=\{scrollLabel\s*\?\s*0/)
  assert.match(table, /if\s*\(!scrollLabel\)\s*return table/)
  assert.equal((table.match(/<HorizontalScrollControls\s+scrollRef=\{scrollRef\}/g) || []).length, 2)
  assert.match(controls, /region\.scrollLeft\s*=\s*Math\.max\(0,\s*region\.scrollWidth\s*-\s*region\.clientWidth\)/)
  assert.match(controls, /onClick=\{jumpToEnd\}\s+disabled=\{!edges\.canRight\}/)
  assert.match(controls, /if\s*\(endLabel\s*&&\s*!edges\.canLeft\s*&&\s*!edges\.canRight\)\s*return null/)
})

test('scroll positions are bounded and all remaining columns can be reached', () => {
  for (const viewport of [250,320,390,768]) {
    const width = 1000
    let left=0
    for(let i=0;i<20;i++) left=nextHorizontalPosition(left,width,viewport,1)
    assert.equal(left,width-viewport)
    assert.deepEqual(horizontalScrollEdges(left,width,viewport), {canLeft:true,canRight:false})
    for(let i=0;i<20;i++) left=nextHorizontalPosition(left,width,viewport,-1)
    assert.equal(left,0)
  }
  assert.deepEqual(horizontalScrollEdges(0,300,500),{canLeft:false,canRight:false})
})

test('only guided identity viewport receives native touch scrolling and accessible controls', () => {
  assert.match(css, /\.identity-directory-page \.ui-table-wrapper--guided\s*\{[^}]*overflow-x:\s*auto;[^}]*touch-action:\s*pan-x pan-y pinch-zoom;/s)
  assert.match(css, /\.ui-table-wrapper--guided\s*\{[^}]*min-width:\s*0;[^}]*max-width:\s*100%/s)
  assert.match(css, /\.siakad-hscroll-actions button\s*\{[^}]*min-width:\s*44px;[^}]*min-height:\s*44px/s)
  assert.doesNotMatch(controls, /window\.scrollTo\(|document\.body\.scroll/)
})

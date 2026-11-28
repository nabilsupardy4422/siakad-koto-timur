import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { candidatePage } from '../src/pages/homeroom/homeroomCandidatePickerLogic.js'
const rows = Array.from({length: 11}, (_, i) => ({ value: String(i+1), label: `Guru ${i+1}`, searchText: `000${i+1}`, description: `${i === 0 ? '0001 · Belum aktivasi' : 'Siap'}` }))
test('candidate search matches names case-insensitively and preserves leading-zero NIP', () => { assert.equal(candidatePage(rows, ' GURU 11 ',1).rows[0].value,'11'); assert.deepEqual(candidatePage(rows,'0002',1).rows.map(r=>r.value),['2']); assert.equal(candidatePage(rows,'Belum aktivasi',1).total,1) })
test('all eligible candidates are reachable with bounded pagination, no silent truncation', () => { assert.equal(candidatePage(rows,'',1).last,3); assert.deepEqual([1,2,3].flatMap(p=>candidatePage(rows,'',p).rows),rows); assert.equal(candidatePage(rows,'',99).page,3) })
test('no matches or empty eligible snapshot remains empty', () => { assert.deepEqual(candidatePage(rows,'not found',4),{rows:[],total:0,page:1,last:1}); assert.equal(candidatePage([], '', 0).total,0) })
const page = readFileSync(new URL('../src/pages/homeroom/HomeroomManagementPage.jsx', import.meta.url),'utf8')
const picker = readFileSync(new URL('../src/pages/homeroom/HomeroomCandidatePicker.jsx', import.meta.url),'utf8')
const css = readFileSync(new URL('../src/pages/homeroom/HomeroomCandidatePicker.css', import.meta.url),'utf8')
test('both create and replacement use a context-keyed picker without modifying candidate API checks', () => { assert.match(page,/\['create','replace'\]\.includes\(mode\)&&<><HomeroomCandidatePicker key=\{candidateKey\}/); assert.doesNotMatch(page,/SearchableSelect/); assert.match(page,/eligibleCandidates\(r\)/); assert.match(page,/candidates\?\.rows\.some\(g=>String\(g\.id\)===guruId\)/) })
test('picker uses explicit click, no blur close, no forced search focus or nested scroll (source contract)', () => { assert.match(picker,/type="button"/); assert.match(picker,/onClick=\{\(\) => choose\(option\)\}/); assert.doesNotMatch(picker,/onBlur=|pointerdown|autoFocus/); assert.match(picker,/event\.key === 'Enter'\) event\.preventDefault\(\)/); assert.doesNotMatch(css,/overflow\s*:\s*(auto|scroll)|position\s*:\s*absolute/); assert.match(css,/min-height:52px/) })

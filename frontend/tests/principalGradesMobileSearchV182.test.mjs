import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { matchesPrincipalStudentSearch } from '../src/pages/grades/roles/principalStudentSearch.js'

const dir = dirname(fileURLToPath(import.meta.url))
const page = readFileSync(resolve(dir, '../src/pages/grades/roles/KepalaSekolahGrades.jsx'), 'utf8')
const css = readFileSync(resolve(dir, '../src/pages/grades/styles/KepalaSekolahGrades.css'), 'utf8')
const row = { studentName: 'UjiSiswa2', nisn: '0058083291', nis: '123455' }

test('filter searches name case-insensitively or NISN including leading zeroes', () => {
  assert.equal(matchesPrincipalStudentSearch(row, 'UJISISWA2'), true)
  assert.equal(matchesPrincipalStudentSearch(row, ' uJiSis '), true)
  assert.equal(matchesPrincipalStudentSearch(row, '0058083291'), true)
  assert.equal(matchesPrincipalStudentSearch(row, '005808'), true)
  assert.equal(matchesPrincipalStudentSearch(row, '123455'), false, 'NIS cannot masquerade as NISN')
  assert.equal(matchesPrincipalStudentSearch(row, '0000000000'), false)
})

test('empty query leaves the existing period/class/subject filters intact', () => {
  assert.equal(matchesPrincipalStudentSearch(row, ''), true)
  assert.equal(matchesPrincipalStudentSearch(row, '   '), true)
  assert.match(page, /matchesPrincipalStudentSearch\(row, studentSearch\)/)
  assert.match(page, /filters\.class_id/)
  assert.match(page, /filters\.subject_id/)
  assert.match(page, /filters\.tingkat/)
})

test('missing NISN never makes the local NIS searchable', () => {
  assert.equal(matchesPrincipalStudentSearch({ ...row, nisn: '-' }, '123455'), false)
  assert.equal(matchesPrincipalStudentSearch({ ...row, nisn: null }, '123455'), false)
  assert.equal(matchesPrincipalStudentSearch({ ...row, nisn: '-' }, 'UjiSiswa2'), true)
})

test('UI has distinct NISN and NIS columns and does not substitute one for another', () => {
  assert.match(page, /<th>NISN<\/th>\s*<th>NIS<\/th>/)
  assert.match(page, /<td className="kepala-grades__identity">\{row\.nisn\}<\/td>/)
  assert.match(page, /<td className="kepala-grades__identity">\{row\.nis\}<\/td>/)
  assert.doesNotMatch(page, /NIS \/ NISN/)
  assert.doesNotMatch(page, /row\.nis !== '-' \? row\.nis : row\.nisn/)
})

test('search resets pagination and is cleared by Reset Filter and when switching period', () => {
  assert.match(page, /const updateSearch = \(value\) => \{\s*setStudentSearch\(value\)\s*setCurrentPage\(1\)/)
  assert.match(page, /if \(name === 'academic_year_id'\) \{\s*setStudentSearch\(''\)/)
  assert.match(page, /const resetFilters = \(\) => \{\s*setStudentSearch\(''\)/)
})

test('scroll controls target the table wrapper, not the entire page', () => {
  assert.match(page, /ref=\{tableScrollRef\}[\s\S]*?onScroll=\{updateTableScroll\}/)
  assert.match(page, /tableScrollRef\.current/)
  assert.match(page, /element\.scrollBy\(\{ left: direction \* distance, behavior: 'smooth' \}\)/)
  assert.match(page, /aria-label="Geser tabel nilai ke kiri"/)
  assert.match(page, /aria-label="Geser tabel nilai ke kanan"/)
  assert.match(css, /\.kepala-grades__table-wrap\s*\{[\s\S]*?overflow-x: auto/)
  assert.match(css, /-webkit-overflow-scrolling: touch/)
})

test('monitoring remains read-only and no backend or TU route is added', () => {
  assert.doesNotMatch(page, /gradeApi\.(create|store|update|delete)/)
  assert.match(page, /scheduleApi\.getAll/)
  assert.match(page, /gradeApi\.getGrades/)
})

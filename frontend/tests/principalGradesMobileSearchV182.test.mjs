import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import { matchesPrincipalStudentSearch } from '../src/pages/grades/roles/principalStudentSearch.js'

const dir = dirname(fileURLToPath(import.meta.url))
const page = readFileSync(resolve(dir, '../src/pages/tu-monitoring/TuAcademicMonitoring.jsx'), 'utf8')
const css = readFileSync(resolve(dir, '../src/pages/tu-monitoring/tu-monitoring.css'), 'utf8')
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
  assert.match(page,/Object.entries\(filters\)/)
  assert.match(page,/academic_year_id:yearId/)
  assert.match(page,/filters\.class_id/)
  assert.match(page,/filters\.subject_id/)
  assert.match(page,/filters\.level/)
})

test('missing NISN never makes the local NIS searchable', () => {
  assert.equal(matchesPrincipalStudentSearch({ ...row, nisn: '-' }, '123455'), false)
  assert.equal(matchesPrincipalStudentSearch({ ...row, nisn: null }, '123455'), false)
  assert.equal(matchesPrincipalStudentSearch({ ...row, nisn: '-' }, 'UjiSiswa2'), true)
})

test('UI has distinct NISN and NIS columns and does not substitute one for another', () => {
  assert.match(page,/\['nisn','NISN'\]/)
  assert.match(page,/\['nis','NIS \(lama\)'\]/)
  assert.match(page,/\['nipd','NIPD'\]/)
  assert.doesNotMatch(page,/row\.nis.*row\.nisn/)
})

test('search resets pagination and is cleared by Reset Filter and when switching period', () => {
  assert.match(page,/function change\(name,value\)\{setPage\(1\)/)
  assert.match(page,/function chooseYear\(value\)\{setYearId\(value\);setFilters\(EMPTY\);setSearchDraft\(''\);setPage\(1\)/)
  assert.match(page,/setFilters\(EMPTY\);setSearchDraft\(''\);setPage\(1\)/)
})

test('scroll controls target the table wrapper, not the entire page', () => {
  assert.match(page,/ref=\{tableRef\} className="tam-table-scroll"/)
  assert.match(page,/tableRef\.current\?\.scrollBy/)
  assert.match(page,/aria-label="Geser tabel nilai ke kiri"/)
  assert.match(page,/aria-label="Geser tabel nilai ke kanan"/)
  assert.match(css,/\.tam-table-scroll\{max-width:100%;overflow:auto/)
  assert.match(css,/content:attr\(data-label\)/)
})

test('monitoring remains read-only and principal uses a separate protected endpoint', () => {
  assert.doesNotMatch(page,/apiClient\.(post|put|patch|delete)/)
  assert.match(page,/principal\?'\/principal\/grade-monitoring'/)
  assert.match(page,/!principal&&<Panel><ExportPanel/)
  const backend=readFileSync(resolve(dir,'../../backend/app/Services/TuGradeDataset.php'),'utf8')
  assert.match(backend,/array_intersect_key\(\$r,array_flip\(\['student_name','nisn'\]\)\)/)
})

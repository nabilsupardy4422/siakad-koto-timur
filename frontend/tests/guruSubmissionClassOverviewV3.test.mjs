import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parseSubmissionClassOverview } from '../src/pages/assignments/roles/submissionClassOverview.js'

const base = () => ({
  assignment_id: 8,
  class_id: 12,
  class_name: 'X IPA 1',
  as_of: '2026-11-23',
  total_students: 30,
  submitted_students: 1,
  not_submitted_students: 29,
})
const assignment = { id: 8, jadwal_pelajaran: { kelas: { id: 12 } } }

test('parses actual full-class progress separate from submission pagination', () => {
  assert.equal(parseSubmissionClassOverview(base(), assignment).submitted_students, 1)
  assert.equal(parseSubmissionClassOverview(base(), assignment).not_submitted_students, 29)
})
test('missing class totals never invents counts', () => {
  assert.equal(parseSubmissionClassOverview(undefined, assignment), null)
})
test('wrong assignment rejects foreign progress', () => {
  const obj = base(); obj.assignment_id = 9
  assert.throws(() => parseSubmissionClassOverview(obj, assignment), /bukan untuk tugas/)
})
test('wrong class rejects foreign progress', () => {
  const obj = base(); obj.class_id = 14
  assert.throws(() => parseSubmissionClassOverview(obj, assignment), /kelas berbeda/)
})
test('rejects impossible or inconsistent class summary', () => {
  const obj = base(); obj.submitted_students = 31
  assert.throws(() => parseSubmissionClassOverview(obj, assignment))
  obj.submitted_students = 5
  assert.throws(() => parseSubmissionClassOverview(obj, assignment))
})
test('rejects invalid negative and fractional totals', () => {
  for (const n of [-1, 1.5, '30', NaN]) {
    const obj = base(); obj.total_students = n
    assert.throws(() => parseSubmissionClassOverview(obj, assignment))
  }
})
test('zero member class yields zero percent, not fabricated 100 percent', () => {
  const obj = base(); obj.total_students = 0; obj.submitted_students = 0; obj.not_submitted_students = 0
  assert.equal(parseSubmissionClassOverview(obj, assignment).total_students, 0)
})
test('rejects malformed as-of and class metadata', () => {
  const obj = base(); obj.as_of = 'some day'
  assert.throws(() => parseSubmissionClassOverview(obj, assignment))
  const name = base(); name.class_name = 12
  assert.throws(() => parseSubmissionClassOverview(name, assignment))
})
test('modal reads server class overview with request guard and clears stale data', () => {
  const file = readFileSync(new URL('../src/pages/assignments/roles/GuruAssignmentSubmissions.jsx', import.meta.url), 'utf8')
  assert.match(file, /parseSubmissionClassOverview\(response\?\.meta\?\.class_overview, assignment\)/)
  assert.match(file, /generation\.current/)
  assert.match(file, /setClassOverview\(null\)/)
  assert.match(file, /classOverview\.submitted_students/)
  assert.match(file, /classOverview\.not_submitted_students/)
  assert.doesNotMatch(file, /setClassOverview\(\{.*submissions\.length/)
})
test('scroll fixes keep student card compact and all attachments in detail scroller', () => {
  const css = readFileSync(new URL('../src/pages/assignments/styles/GuruAssignmentSubmissions.css', import.meta.url), 'utf8')
  assert.match(css, /\.gs-students\s*\{[^}]*display:\s*flex;/)
  assert.match(css, /\.gs-student\s*\{[^}]*flex:\s*0 0 auto;/)
  assert.match(css, /\.gs-detail\s*\{[^}]*display:\s*flex;[^}]*overflow-y:\s*auto;/)
  assert.match(css, /\.gs-detail \.ga-file-list\s*\{[^}]*overflow:\s*visible;/)
  assert.match(css, /\.gs-detail > \*\s*\{[^}]*flex:\s*0 0 auto;/)
})
test('roster class progress is additive to existing teacher modal without new route', () => {
  const file = readFileSync(new URL('../src/pages/assignments/roles/GuruAssignmentSubmissions.jsx', import.meta.url), 'utf8')
  assert.match(file, /assignmentApi\.getSubmissions\(assignment\.id, \{ page \}\)/)
  assert.match(file, /downloadSubmissionAttachment/)
  assert.match(file, /gs-class-overview/)
  assert.match(file, /gs-students/)
})

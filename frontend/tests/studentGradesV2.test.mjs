import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { formatGrade, formatWeight, uniqueTeacherNames, validateWorkspace } from '../src/pages/grades/roles/studentGradeView.js'

const base = () => ({ data: { year: { id: 1 }, classes: [], summary: { subjects: 1, components: 1, assessed: 1, pending: 0 }, subjects: [{ key: '1:2', teachers: [{ name: 'Haikal' }, { name: 'Haikal' }, { name: 'Nabil' }], components: [{ id: 1, assessed: true, score: 0 }] }] } })
test('nilai numerik nol bukan nilai kosong', () => { assert.equal(formatGrade(0), '0'); assert.equal(formatGrade(null), '—'); assert.equal(formatWeight(0), '0%') })
test('nama guru deduplikasi', () => assert.equal(uniqueTeacherNames(base().data.subjects[0].teachers), 'Haikal · Nabil'))
test('workspace valid mempertahankan skor nol', () => assert.equal(validateWorkspace(base()).subjects[0].components[0].score, 0))
test('workspace tanpa kontrak ditolak', () => assert.throws(() => validateWorkspace({ data: { subjects: [] } })))
test('duplikat konteks kelas/mapel ditolak', () => { const p = base(); p.data.subjects.push(p.data.subjects[0]); assert.throws(() => validateWorkspace(p)) })
test('status nilai dan skor tidak konsisten ditolak', () => { const p = base(); p.data.subjects[0].components[0].score = null; assert.throws(() => validateWorkspace(p)) })
test('UI menggunakan state untuk detail dan pengaman request lama', () => { const s = readFileSync(new URL('../src/pages/grades/roles/SiswaGrades.jsx', import.meta.url), 'utf8'); assert.match(s, /setSubjectKey/); assert.match(s, /seq\.current/); assert.doesNotMatch(s, /window\.location/) })
test('API hanya mengirim ID periode bukan identity override', () => { const s = readFileSync(new URL('../src/services/studentGradeWorkspaceApi.js', import.meta.url), 'utf8'); assert.match(s, /academic_year_id/); assert.doesNotMatch(s, /student_id|class_id/) })

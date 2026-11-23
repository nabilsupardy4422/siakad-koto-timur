import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { parseStudentDashboard, formatWibDate, formatDeadline } from '../src/pages/dashboard/roles/studentDashboardView.js'
const valid = () => ({ data: {
 academic_year: { id: 1, label: '2026/2027 · Ganjil' }, as_of: '2026-11-23', today_name: 'SENIN', student_name: 'Siswa',
 placement_status: 'active', class: {id: 1, nama: 'IPA 1'},
 today_schedule: [{id: 10, subject: 'Matematika', teacher: 'Pak Guru', start: '07:30', end: '09:00'}],
 assignment_summary: {total: 2, pending: 1, submitted: 1, overdue: 1, late_submitted: 0},
 upcoming_assignments: [{id: 5, title: 'Tugas', status: 'overdue', deadline: null}],
 subjects: [{id: 1, name: 'Matematika', teachers: ['Pak Guru'], total:2, pending:1}], recent_materials: [],
}})
test('reads actual apiClient JSON envelope only once', () => { assert.equal(parseStudentDashboard(valid()).class.nama,'IPA 1'); assert.throws(() => parseStudentDashboard({data:valid()})) })
test('rejects missing placement context', () => { const p=valid();p.data.placement_status='unexpected';assert.throws(()=>parseStudentDashboard(p)) })
test('rejects scope leakage on unassigned student', () => { const p=valid();p.data.placement_status='unassigned';p.data.class=null;assert.throws(()=>parseStudentDashboard(p)) })
test('accepts empty unassigned dashboard', () => { const p=valid();p.data.placement_status='unassigned';p.data.class=null;p.data.today_schedule=[];p.data.upcoming_assignments=[];p.data.subjects=[];p.data.assignment_summary={total:0,pending:0,submitted:0,overdue:0,late_submitted:0};assert.equal(parseStudentDashboard(p).placement_status,'unassigned') })
test('rejects inconsistent submitted counts', () => {const p=valid();p.data.assignment_summary.submitted=5;assert.throws(()=>parseStudentDashboard(p))})
test('rejects submission that is not pending or overdue',()=>{const p=valid();p.data.upcoming_assignments[0].status='submitted';assert.throws(()=>parseStudentDashboard(p))})
test('rejects repeated task ids',()=>{const p=valid();p.data.upcoming_assignments.push(p.data.upcoming_assignments[0]);assert.throws(()=>parseStudentDashboard(p))})
test('formats server WIB date without local timezone conversion',()=>{assert.match(formatWibDate('2026-11-23'),/23/);assert.equal(formatWibDate(''), '—')})
test('does not invent absent deadline',()=>{assert.equal(formatDeadline(null),'Tanpa tenggat')})
test('frontend uses read-only student endpoint and no student override',()=>{const api=readFileSync(new URL('../src/services/studentDashboardWorkspaceApi.js',import.meta.url),'utf8');assert.match(api,/\/dashboard\/student-workspace/);assert.doesNotMatch(api,/student_id|siswa_id/)})
test('React protects stale asynchronous requests',()=>{const js=readFileSync(new URL('../src/pages/dashboard/roles/SiswaDashboard.jsx',import.meta.url),'utf8');assert.match(js,/generation\.current/);assert.match(js,/parseStudentDashboard/);assert.match(js,/setDashboard\(null\)/)})
test('dashboard navigates to existing student modules',()=>{const js=readFileSync(new URL('../src/pages/dashboard/roles/SiswaDashboard.jsx',import.meta.url),'utf8');for(const path of ['/app/assignments','/app/schedules','/app/materials','/app/report-cards'])assert.ok(js.includes(path))})

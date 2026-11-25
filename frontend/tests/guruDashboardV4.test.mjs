import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import path from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const read = (file) => readFileSync(path.join(root, file), 'utf8')
const component = read('src/pages/dashboard/roles/GuruDashboard.jsx')
const css = read('src/pages/dashboard/roles/GuruDashboard.css')
const dispatcher = read('src/pages/dashboard/DashboardPage.jsx')
const expectedSummaryKeys = [
  'teaching_schedule_count', 'material_count', 'assignment_count',
  'grade_component_count', 'attendance_record_count',
]

test('Guru uses its own dashboard and leaves TU/Siswa/Kepala Sekolah entrypoints intact', () => {
  assert.match(dispatcher, /roleCode === 'GURU'[\s\S]*?<GuruDashboard\s*\/>/)
  for (const name of ['TUDashboard', 'SiswaDashboard', 'KepalaSekolahDashboard']) {
    assert.ok(dispatcher.includes(name))
  }
  assert.match(component, /useDashboard\(\)/)
  assert.match(component, /from '\.\/GuruDashboard\.css'|import '\.\/GuruDashboard\.css'/)
})

test('five summary cards use only real existing backend summary fields', () => {
  for (const field of expectedSummaryKeys) assert.ok(component.includes(`key: '${field}'`), field)
  assert.match(component, /SUMMARY_ITEMS\.map\(/)
  assert.match(component, /formatCount\(summary\[item\.key\]\)/)
  assert.match(component, /return '—'/)
})

test('today agenda renders actual per-schedule day, class, subject and times', () => {
  assert.match(component, /Array\.isArray\(dashboard\.today_schedule\)/)
  assert.match(component, /entries\.map\(/)
  for (const expression of ['row.jam_mulai', 'row.jam_selesai', 'row.mata_pelajaran?.nama', 'row.kelas', 'row.hari']) {
    assert.ok(component.includes(expression), expression)
  }
  assert.match(component, /Terjadwal tidak berarti pertemuan sudah berlangsung/)
  assert.doesNotMatch(component, /guru_hadir|guru_tidak_hadir|konfirmasi_mengajar/i)
})

test('activity panels use API recent arrays rather than fabricated totals', () => {
  assert.match(component, /Array\.isArray\(recent\?\.materials\)/)
  assert.match(component, /Array\.isArray\(recent\?\.assignments\)/)
  assert.match(component, /recent=\{dashboard\.recent\}/)
  assert.match(component, /\.slice\(0, 3\)/)
  assert.match(component, /Belum ada bahan ajar tersimpan/)
  assert.match(component, /Belum ada tugas tersimpan/)
})

test('quick links and latest activity are permission-gated with existing paths', () => {
  for (const rule of ['schedules.view','attendance.view','materials.view','assignments.view','grades.view']) {
    assert.ok(component.includes(rule), rule)
  }
  assert.match(component, /QUICK_LINKS\.filter\(\(link\) => permissions\.includes\(link\.permission\)\)/)
  assert.match(component, /const canMaterials = permissions\.includes\('materials\.view'\)/)
  assert.match(component, /const canAssignments = permissions\.includes\('assignments\.view'\)/)
  for (const url of ['/app/schedules','/app/attendance','/app/materials','/app/assignments','/app/grades']) assert.ok(component.includes(url))
})

test('Wali Kelas is an authorized teaching assignment and preserves existing class resolution', () => {
  assert.match(component, /dashboard\?\.is_wali_kelas === true && assignments\.some\(/)
  assert.match(component, /a\.type === 'WALI_KELAS'/)
  assert.match(component, /useHomeroomDetails/)
  assert.match(component, /matchesTeacher/)
  assert.match(component, /classDetails\(dashboard\?\.wali_kelas\)/)
  assert.match(component, /to="\/app\/homeroom"/)
  assert.match(component, /bukan role akun baru/)
})

test('desktop, tablet and mobile layouts use scoped responsive CSS with no forced page scroll', () => {
  assert.match(component, /className="dashboard-page guru-v4"/)
  assert.match(css, /\.guru-v4\s*\{/)
  assert.match(css, /@media\(max-width:1180px\)/)
  assert.match(css, /@media\(max-width:920px\)/)
  assert.match(css, /@media\(max-width:600px\)/)
  assert.match(css, /@media\(max-width:350px\)/)
  assert.match(css, /grid-template-columns:\s*repeat\(2,minmax\(0,1fr\)\)/)
  assert.doesNotMatch(css, /(^|\n)\s*(body|html|\.sidebar|\.topbar)\s*\{/m)
})

test('loading, error, empty, headings and focus remain accessible', () => {
  assert.match(component, /if \(loading\) return <LoadingState/)
  assert.match(component, /if \(error\) return <ErrorState/)
  assert.match(component, /if \(!dashboard\) return <ErrorState/)
  assert.match(component, /aria-labelledby="guru-v4-title"/)
  assert.match(component, /aria-labelledby="guru-agenda-heading"/)
  assert.match(component, /aria-live="polite"/)
  assert.match(css, /a:focus-visible/)
  assert.match(css, /prefers-reduced-motion/)
})

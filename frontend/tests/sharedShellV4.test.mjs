import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import navigation from '../src/Config/navigation.js'
import { selectVisibleSidebarItems } from '../src/app/sidebarNavigation.js'

const src = (file) => readFileSync(new URL(`../src/app/${file}`, import.meta.url), 'utf8')
const labels = (items) => items.filter((item) => !item.section).map((item) => item.label)
const sections = (items) => items.filter((item) => item.section).map((item) => item.label)

test('Siswa menu keeps authorized academic modules and does not inherit TU administration', () => {
  const allowed = selectVisibleSidebarItems(navigation, 'SISWA', [
    'schedules.view', 'attendance.view', 'grades.view', 'materials.view',
    'assignments.view', 'report_cards.view', 'reports.view',
  ])
  for (const label of ['Dashboard', 'Jadwal', 'Presensi', 'Nilai', 'Bahan Ajar', 'Tugas', 'Rapor']) {
    assert.ok(labels(allowed).includes(label), label)
  }
  for (const label of ['Pengguna', 'Guru', 'Manajemen Wali Kelas']) assert.ok(!labels(allowed).includes(label), label)
})

test('Guru mapel only has teaching links without wali kelas assignment', () => {
  const menu = selectVisibleSidebarItems(navigation, { code: 'GURU' }, [
    'schedules.view', 'attendance.view', 'materials.view',
    'assignments.view', 'grades.view', 'reports.view',
  ], [])
  assert.ok(labels(menu).includes('Jadwal Mengajar'))
  assert.ok(labels(menu).includes('Bahan Ajar'))
  assert.ok(!labels(menu).includes('Kelas Saya'))
  assert.ok(!labels(menu).includes('Anggota Kelas'))
  assert.ok(!sections(menu).includes('Wali Kelas'))
})

test('Wali kelas links become visible only after explicit WALI_KELAS assignment', () => {
  const menu = selectVisibleSidebarItems(navigation, 'GURU', ['schedules.view'], [{ type: 'WALI_KELAS' }])
  assert.ok(labels(menu).includes('Kelas Saya'))
  assert.ok(labels(menu).includes('Anggota Kelas'))
  assert.ok(sections(menu).includes('Wali Kelas'))
})

test('TU keeps master data, account and reporting according to permission, no material link', () => {
  const menu = selectVisibleSidebarItems(navigation, 'TU', [
    'teachers.view', 'students.view', 'subjects.view', 'classes.view',
    'schedules.view', 'attendance.recap', 'grades.recap',
    'assignments.view', 'materials.view', 'report_cards.view',
    'reports.view', 'users.view',
  ])
  assert.ok(labels(menu).includes('Pengguna'))
  assert.ok(labels(menu).includes('Tahun Akademik'))
  assert.ok(!labels(menu).includes('Bahan Ajar'))
})

test('Kepala Sekolah receives only monitoring/reporting links', () => {
  const menu = selectVisibleSidebarItems(navigation, 'KEPALA_SEKOLAH', [
    'attendance.recap', 'grades.recap', 'schedules.view', 'reports.view', 'users.view',
  ])
  assert.deepEqual(labels(menu), ['Dashboard', 'Presensi', 'Penilaian', 'Jadwal', 'Laporan'])
})

test('No unknown role or missing permission can manufacture links', () => {
  assert.deepEqual(selectVisibleSidebarItems(navigation, 'UNKNOWN', ['users.view']), [])
  const siswa = selectVisibleSidebarItems(navigation, 'SISWA', [])
  assert.deepEqual(labels(siswa), ['Dashboard'])
  assert.deepEqual(sections(siswa), [])
})

test('Section without any permitted links is omitted, ordering of links is retained', () => {
  const items = selectVisibleSidebarItems(navigation, 'TU', [])
  assert.deepEqual(labels(items), ['Dashboard', 'Tahun Akademik', 'Anggota Kelas', 'Manajemen Wali Kelas'])
  assert.ok(!sections(items).includes('Manajemen Akun'))
  assert.equal(items[0].label, 'Dashboard')
})

test('Collapse button is inside brand header and the old utility bar is gone', () => {
  const jsx = src('Sidebar.jsx')
  const header = jsx.indexOf('className="app-sidebar__header"')
  const collapse = jsx.indexOf('className="app-sidebar__collapse"')
  const nav = jsx.indexOf('className="app-sidebar__nav"')
  assert.ok(header >= 0 && header < collapse && collapse < nav)
  assert.ok(!jsx.includes('app-sidebar__utility'))
  assert.ok(jsx.includes('aria-expanded={!collapsed}'))
  assert.ok(jsx.includes('className="app-sidebar__close"'))
})

test('Compact mode preserves navigation labels, tooltip and active route', () => {
  const jsx = src('Sidebar.jsx')
  const css = src('Sidebar.css')
  assert.match(jsx, /selectVisibleSidebarItems\(navigation, role, permissions, assignments\)/)
  assert.match(jsx, /isActive\s*\?\s*"is-active"/)
  assert.match(jsx, /createPortal\(/)
  assert.match(css, /\.app-sidebar\.is-collapsed \.app-sidebar__link-label/)
  assert.match(css, /@media \(max-width: 1023px\)/)
  assert.match(css, /\.app-sidebar\.is-open/)
})

test('Shell keeps mobile focus trap, Escape, skip link and collapse preference', () => {
  const jsx = src('AppShell.jsx')
  const css = src('AppShell.css')
  assert.match(jsx, /localStorage\.getItem/)
  assert.match(jsx, /localStorage\.setItem/)
  assert.match(jsx, /event\.key === 'Escape'/)
  assert.match(jsx, /mainContent\.inert = true/)
  assert.match(jsx, /Lewati navigasi, ke konten/)
  assert.match(jsx, /tabIndex=\{-1\}/)
  assert.match(css, /is-sidebar-collapsed/)
})

test('Topbar retains mobile menu, breadcrumbs, website link and account menu', () => {
  const jsx = src('Topbar.jsx')
  const css = src('Topbar.css')
  assert.match(jsx, /onClick=\{onMenuClick\}/)
  assert.match(jsx, /<Breadcrumb\s*\/>/)
  assert.match(jsx, /<UserMenu\s*\/>/)
  assert.match(jsx, /to="\/"/)
  assert.match(css, /@media \(max-width: 639px\)/)
  assert.match(css, /\.app-topbar__menu/)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import {
  PRINCIPAL_REPORT_TYPES, PRINCIPAL_REPORT_FIELDS,
  parseYears, parseCatalogPage, loadEntireCatalog,
  makePrincipalExportParams, positiveId, formatReportPeriod,
} from '../src/pages/reports/roles/principalReportContract.js'

const current = [{ id: 3, tahun_mulai: 2026, tahun_selesai: 2027, semester: 'GANJIL', is_active: true }]

test('Kepala Sekolah only gets the seven report types already authorized by the backend', () => {
  assert.deepEqual(PRINCIPAL_REPORT_TYPES.map(i => i.id), ['students', 'teachers', 'classes', 'schedules', 'attendance', 'grades', 'semester-recap'])
  assert.deepEqual(PRINCIPAL_REPORT_FIELDS.teachers, ['search'])
  assert(!PRINCIPAL_REPORT_FIELDS.grades.includes('status'))
})

test('academic years are a complete unpaginated collection, semester is part of the selected period', () => {
  assert.equal(parseYears({ data: current })[0].id, 3)
  assert.equal(formatReportPeriod(current[0]), '2026/2027 · GANJIL (Aktif)')
  assert.throws(() => parseYears({ data: { data: current } }), /kontrak/)
  assert.throws(() => parseYears({ data: [...current, ...current] }), /tidak valid/)
  assert.throws(() => parseYears({ data: [{ ...current[0], is_active: 'maybe' }] }), /tidak valid/)
  assert.throws(() => parseYears({ data: [{ ...current[0], id: 4 }, current[0]] }), /lebih dari satu/)
})

test('all catalogue pages must match Laravel pagination metadata', async () => {
  const call = []
  const records = await loadEntireCatalog(async page => {
    call.push(page)
    return { data: [{ id: page, nama: `Kelas ${page}` }], meta: { current_page: page, last_page: 3, per_page: 1, total: 3 } }
  })
  assert.deepEqual(call, [1, 2, 3])
  assert.deepEqual(records.map(c => c.id), [1, 2, 3])
  assert.throws(() => parseCatalogPage({ data: [] }, 1), /Pagination/)
  assert.throws(() => parseCatalogPage({ data: [], meta: { current_page: 1, last_page: 1, per_page: 10, total: 2 } }, 1), /tidak konsisten/)
  await assert.rejects(() => loadEntireCatalog(async () => ({ data: [], meta: { current_page: 1, last_page: 2, per_page: 1, total: 2 } })), /tidak konsisten/)
})

test('pagination data changing and duplicate IDs fail closed', async () => {
  await assert.rejects(() => loadEntireCatalog(async page => ({ data: [{ id: 7 }], meta: { current_page: page, last_page: 2, per_page: 1, total: 2 } })), /duplikat/)
  await assert.rejects(() => loadEntireCatalog(async page => ({ data: [{ id: page }], meta: { current_page: page, last_page: page === 1 ? 2 : 3, per_page: 1, total: page === 1 ? 2 : 3 } })), /berubah/)
})

test('only positive safe integer IDs can reach the request', () => {
  assert.equal(positiveId('12'), 12)
  for (const value of ['001x', '-1', '0', '1.5', '', '9999999999999999']) assert.equal(positiveId(value), null)
  assert.throws(() => makePrincipalExportParams('grades', '1.5', {}, 'pdf', current), /Pilih tahun/)
})

test('export sends only permitted fields, school scope and the selected year; never a duplicate semester', () => {
  const params = makePrincipalExportParams('schedules', 3, { class_id: '12', subject_id: '4', teacher_id: '9', search: 'ignored', semester: 'GENAP', status: 'inactive' }, 'excel', current)
  assert.deepEqual(params, { scope: 'school', format: 'excel', academic_year_id: 3, class_id: 12, subject_id: 4, teacher_id: 9 })
  assert.deepEqual(makePrincipalExportParams('teachers', '', { search: '  Pak Budi  ', status: 'inactive', class_id: 5 }, 'pdf', []), { scope: 'school', format: 'pdf', search: 'Pak Budi' })
})

test('attendance dates validate calendar correctness and end-before-start', () => {
  assert.deepEqual(makePrincipalExportParams('attendance', '3', { date_from: '2026-11-01', date_to: '2026-11-25' }, 'pdf', current), { scope: 'school', format: 'pdf', academic_year_id: 3, date_from: '2026-11-01', date_to: '2026-11-25' })
  assert.throws(() => makePrincipalExportParams('attendance', '3', { date_from: '2026-02-30' }, 'pdf', current), /Tanggal/)
  assert.throws(() => makePrincipalExportParams('attendance', '3', { date_from: '2026-11-25', date_to: '2026-11-01' }, 'pdf', current), /mendahului/)
})

test('report page routes each role to its dedicated workspace', async () => {
  const page = await readFile(new URL('../src/pages/reports/ReportsPage.jsx', import.meta.url), 'utf8')
  assert.match(page, /roleCode === 'GURU'\) return <GuruReports key=\{user.id\}/)
  assert.match(page, /roleCode === 'KEPALA_SEKOLAH'\) return <KepalaSekolahReports key=\{user.id\}/)
  assert.match(page, /roleCode === 'SISWA'\) return <SiswaReports key=\{user.id\}/)
  assert.match(page, /roleCode === 'TU'\) return <TuReports key=\{user.id\}/)
  const source = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url), 'utf8')
  assert.match(source, /<TuReportPreviewPanel[^>]*principal\//)
  assert.doesNotMatch(source, /reportApi\.preview\(/)
  assert.doesNotMatch(source, /\b(?:post|put|delete|patch)\s*\(/)
  assert.match(source, /Pratinjau dan ekspor berasal dari dataset yang sama/)
})

test('the new principal report page remains responsive and has loading, errors and export accessibility', async () => {
  const css = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.css', import.meta.url), 'utf8')
  const jsx = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url), 'utf8')
  for (const width of [1100, 850, 600, 340]) assert(css.includes(`max-width: ${width}px`))
  assert.match(css, /minmax\(0,1fr\)/)
  assert.match(css, /\.ks-report__export-grid/)
  const preview=await readFile(new URL('../src/pages/reports/roles/TuReportPreviewPanel.jsx',import.meta.url),'utf8')
  assert.match(preview,/aria-busy=\{busy\}/)
  assert.match(preview,/role="alert"/)
  assert.match(preview,/disabled=\{!can\|\|busy\}/)
  assert.match(jsx,/enabled=\{!!params&&!exportDisabled\}/)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import {
  getAcademicYears, getCollection, getAllPages, isActivePeriod, strictId, scoreOf,
} from '../src/pages/grades/roles/principalGradeIntegrity.js'

const page = (data, current = 1, last = 1) => ({
  data, meta: { current_page: current, last_page: last, per_page: 100, total: data.length },
})

test('academic year API is a complete unpaginated collection', () => {
  const periods = [
    { id: 3, semester: 'GANJIL', is_active: true },
    { id: 2, semester: 'GENAP', is_active: false },
  ]
  assert.deepEqual(getAcademicYears({ data: periods }), periods)
  assert.deepEqual(getAcademicYears({ data: [] }), [])
  for (const invalid of [null, {}, { data: {} }, { data: null },
    { data: [], meta: { current_page: 1, last_page: 1 } },
  ]) assert.throws(() => getAcademicYears(invalid), /tahun akademik/)
  // The unrelated endpoints must continue requiring pagination metadata.
  assert.throws(() => getCollection({ data: [] }, 1), /pagination akademik/)
})

test('principal grade page uses one unpaginated period catalog and server monitoring', async () => {
  const jsx = await readFile(new URL('../src/pages/grades/roles/KepalaSekolahGrades.jsx', import.meta.url), 'utf8')
  const shared = await readFile(new URL('../src/pages/tu-monitoring/TuAcademicMonitoring.jsx', import.meta.url), 'utf8')
  assert.match(jsx, /<TuAcademicMonitoring grade principal/)
  assert.match(shared, /academicYearApi\.getAll\(\)\.then\(yearsFrom\)/)
  assert.match(shared, /principal\?'\/principal\/grade-monitoring'/)
  assert.match(shared, /\(!grade\|\|filters\.class_id\)/)
  assert.doesNotMatch(shared, /gradeApi\.getGrades|scheduleApi\.getAll/)
})

test('0 is a real recorded score; missing score stays null', () => {
  assert.equal(scoreOf({ nilai: 0 }), 0)
  assert.equal(scoreOf({ nilai: '0.00' }), 0)
  assert.equal(scoreOf({ nilai: 100 }), 100)
  assert.equal(scoreOf(undefined), null)
  for (const invalid of [null, '', -1, 101, 'abc', Infinity]) {
    assert.throws(() => scoreOf({ nilai: invalid }))
  }
})

test('academic periods allow active boolean and numeric flag but not arbitrary truthiness', () => {
  assert.equal(isActivePeriod({ is_active: true }), true)
  assert.equal(isActivePeriod({ is_active: 1 }), true)
  assert.equal(isActivePeriod({ is_active: '1' }), true)
  assert.equal(isActivePeriod({ is_active: 'false' }), false)
  assert.equal(isActivePeriod({ is_active: 0 }), false)
  assert.equal(isActivePeriod(null), false)
})

test('only positive safe integer identifiers are accepted', () => {
  assert.equal(strictId('42'), 42)
  for (const invalid of [null, undefined, -1, 0, '1x', '1.5', 3.1, Number.MAX_SAFE_INTEGER + 1]) {
    assert.throws(() => strictId(invalid))
  }
})

test('a missing or inconsistent paginated API response is not treated as empty results', () => {
  assert.deepEqual(getCollection(page([1], 1, 1), 1).data, [1])
  for (const invalid of [null, { data: {} }, { data: [] },
    { data: [], meta: { current_page: 2, last_page: 2 } },
    { data: [], meta: { current_page: 1, last_page: 0 } },
    { data: [], meta: { current_page: 1, last_page: 101 } },
  ]) assert.throws(() => getCollection(invalid, 1))
})

test('all API pages are loaded and no silent truncation is permitted', async () => {
  const fetched = []
  const rows = await getAllPages(async (n, perPage) => {
    fetched.push([n, perPage])
    return page([`row-${n}`], n, 3)
  }, 25)
  assert.deepEqual(rows, ['row-1', 'row-2', 'row-3'])
  assert.deepEqual(fetched, [[1, 25], [2, 25], [3, 25]])
  await assert.rejects(() => getAllPages(async (n) => page([n], n, n === 1 ? 2 : 3)), /Pagination berubah/)
  await assert.rejects(() => getAllPages(async () => ({ data: [] })), /pagination akademik/)
})

test('historical scores use stored records and never manufacture current-roster missing slots', async () => {
  const backend=await readFile(new URL('../../backend/app/Services/TuGradeDataset.php',import.meta.url),'utf8')
  assert.match(backend,/\$slots=\$active\?\$roster->count\(\)\*\$group->count\(\):null/)
  assert.match(backend,/if\(\$active\)\{abort_if\(\$summary\['Slot saat ini'\]>30000/)
  assert.match(backend,/Riwayat tercatat/)
  const shared=await readFile(new URL('../src/pages/tu-monitoring/TuAcademicMonitoring.jsx',import.meta.url),'utf8')
  assert.match(shared,/Rombel historis menampilkan catatan tersimpan saja/)
  assert.match(shared,/return\(\)=>\{alive=false\}/)
})

test('principal grade page remains read only; semester is fixed by selected year', async () => {
  const jsx=await readFile(new URL('../src/pages/tu-monitoring/TuAcademicMonitoring.jsx',import.meta.url),'utf8')
  assert.match(jsx,/Tahun Akademik/)
  assert.match(jsx,/year\.semester/)
  assert.doesNotMatch(jsx,/filters\.semester|next\.semester/)
  assert.match(jsx,/apiClient\.get/)
  assert.doesNotMatch(jsx,/apiClient\.(post|put|delete|patch)\(/)
  assert.match(jsx,/!principal&&<Panel><ExportPanel/)
})

test('grade monitoring filters retain distinct responsive controls', async () => {
  const jsx=await readFile(new URL('../src/pages/tu-monitoring/TuAcademicMonitoring.jsx',import.meta.url),'utf8')
  const css=await readFile(new URL('../src/pages/tu-monitoring/tu-monitoring.css',import.meta.url),'utf8')
  for(const text of ['Tahun Akademik','Tingkat','Rombel','Mata pelajaran','Guru','Jadwal','Komponen']) assert(jsx.includes(text))
  assert.match(css,/grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/)
  assert.match(css,/@media\(max-width:850px\)/)
  assert.match(css,/@media\(max-width:600px\)/)
})

import test from 'node:test'
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { fileKind, fileSize, formatDateWib, parseMaterialsWorkspace, uniqueTeachers } from '../src/pages/materials/roles/studentMaterialsView.js'
const source = readFileSync(new URL('../src/pages/materials/roles/SiswaMaterialsPage.jsx', import.meta.url), 'utf8')
const api = readFileSync(new URL('../src/services/studentMaterialWorkspaceApi.js', import.meta.url), 'utf8')
const sample = { data: { data: { academic_year: { id: 1 }, placement_status: 'active', subjects: [{ id: 2, name: 'Matematika', teachers: ['Haikal', 'Nabil'], material_count: 0 }], materials: null } } }
test('workspace valid and preserves zero materials', () => { assert.equal(parseMaterialsWorkspace(sample).subjects[0].material_count, 0) })
test('invalid payload fails closed', () => { assert.throws(() => parseMaterialsWorkspace({ data: {} })); assert.throws(() => parseMaterialsWorkspace({ data: { data: { ...sample.data.data, subjects: [sample.data.data.subjects[0], sample.data.data.subjects[0]] } } })) })
test('pagination contract validated', () => { assert.equal(parseMaterialsWorkspace({ data: { data: { ...sample.data.data, materials: { data: [], total: 0, page: 1, last_page: 1 } } } }).materials.page, 1); assert.throws(() => parseMaterialsWorkspace({ data: { data: { ...sample.data.data, materials: { data: [], total: 0, page: 0, last_page: 1 } } } })) })
test('file type uses real extension and mime', () => { assert.equal(fileKind('slide.pptx'), 'PPT'); assert.equal(fileKind('dok.pdf'), 'PDF'); assert.equal(fileKind('foto.png'), 'IMG'); assert.equal(fileKind('a.bin'), 'FILE') })
test('file sizes and dates do not fabricate fields', () => { assert.equal(fileSize(null), ''); assert.equal(fileSize(1048576), '1.0 MB'); assert.equal(formatDateWib(null), 'Tanggal tidak tersedia') })
test('teacher names deduplicated', () => { assert.deepEqual(uniqueTeachers(['Haikal','Nabil','Haikal']), ['Haikal','Nabil']) })
test('React uses request generation guard and state, not browser redirect', () => { assert.match(source, /generation\.current/); assert.match(source, /setSubjectId\(id\)/); assert.doesNotMatch(source, /window\.location\s*=/) })
test('client never sends student identity override', () => { assert.match(api, /materials\/student-workspace/); assert.doesNotMatch(source, /student_id\s*:/); assert.doesNotMatch(source, /siswa_id\s*:/) })

import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'

const contractUrl = new URL('../src/pages/reports/roles/principalReportContract.js', import.meta.url)
const jsxUrl = new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url)
const routeUrl = new URL('../src/pages/reports/ReportsPage.jsx', import.meta.url)
const reportApiUrl = new URL('../src/services/reportApi.js', import.meta.url)
const required = [
  'PRINCIPAL_REPORT_TYPES','PRINCIPAL_REPORT_FIELDS','REPORT_FILE_LABEL',
  'positiveId','parseYears','parseCatalogPage','loadEntireCatalog',
  'makePrincipalExportParams','formatReportPeriod',
]
const expectedHash = '9d79728dc14e4448451edd3b7640b008f2b71d6bce715fc95d6f78355e890ff1'

try {
  const content = await readFile(contractUrl)
  const hash = createHash('sha256').update(content).digest('hex')
  assert.equal(hash, expectedHash, 'File principalReportContract.js di komputer belum sama dengan ZIP V1.10.1. Pastikan lokasi salin benar.')
  const helper = await import(contractUrl.href + '?check-v1101')
  const missing = required.filter(name => !(name in helper))
  assert.deepEqual(missing, [], `Named export hilang: ${missing.join(', ')}`)
  assert.equal(helper.PRINCIPAL_REPORT_TYPES.length, 7)
  const period = [{id: 3, tahun_mulai: 2026, tahun_selesai: 2027, semester: 'GANJIL', is_active: true}]
  assert.equal(helper.parseYears({data: period})[0].id, 3)
  assert.deepEqual(
    helper.makePrincipalExportParams('schedules', 3, {class_id: '2'}, 'pdf', period),
    {scope: 'school', format: 'pdf', academic_year_id: 3, class_id: 2}
  )
  const page = await readFile(routeUrl, 'utf8')
  const component = await readFile(jsxUrl, 'utf8')
  const api = await readFile(reportApiUrl, 'utf8')
  assert.match(page, /roleCode === 'KEPALA_SEKOLAH'/)
  assert.match(component, /reportApi\.downloadReport\(type, params\)/)
  assert.match(component, /principalReportContract\.js/)
  assert.match(api, /downloadReport\(reportType, params/)
  console.log('PASS: checksum kontrak benar; 9 named exports tersedia; pemilihan role & endpoint unduh terhubung.')
  console.log('Lanjutkan: node --test tests/principalReportsV110.test.mjs, node --test tests/*.test.mjs, npm run build')
} catch (error) {
  console.error('GAGAL pemeriksaan pemasangan Laporan V1.10.1:', error?.message || String(error))
  process.exitCode = 1
}

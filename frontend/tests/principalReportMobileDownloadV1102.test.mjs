import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { makePreparedDownload, makeSharedFile, isNativeFileShareAvailable } from '../src/pages/reports/roles/principalReportDownload.js'

const pdf = new Blob(['%PDF-1.7\n'], { type: 'application/pdf' })
const xlsx = new Blob(['PK\x03\x04'], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' })
const urlApi = { createObjectURL: blob => blob === pdf ? 'blob:https://siakad/pdf' : 'blob:https://siakad/xlsx' }

test('PDF and Excel Blobs become prepared, revocable URLs without network or fake downloads', () => {
  assert.deepEqual(makePreparedDownload(pdf, 'laporan-nilai-2026-ganjil.pdf', 'pdf', urlApi), {
    blob: pdf, format: 'pdf', filename: 'laporan-nilai-2026-ganjil.pdf', url: 'blob:https://siakad/pdf',
  })
  assert.equal(makePreparedDownload(xlsx, 'laporan-nilai-2026-ganjil.xlsx', 'excel', urlApi).url, 'blob:https://siakad/xlsx')
})

test('bad MIME, empty blob, fake filename and invalid format fail rather than appearing successful', () => {
  for (const blob of [new Blob([]), new Blob(['oops'], { type: 'text/html' }), new Blob(['{}'], { type: 'application/json' })]) {
    assert.throws(() => makePreparedDownload(blob, 'laporan-nilai.pdf', 'pdf', urlApi), /valid/)
  }
  assert.throws(() => makePreparedDownload(pdf, '../laporan.pdf', 'pdf', urlApi), /Nama/)
  assert.throws(() => makePreparedDownload(pdf, 'laporan-nilai.exe', 'pdf', urlApi), /Nama/)
  assert.throws(() => makePreparedDownload(pdf, 'laporan-nilai.pdf', 'zip', urlApi), /Format/)
})

test('native file share requires both share and canShare and a real supported File', () => {
  const prepared = makePreparedDownload(pdf, 'laporan-nilai.pdf', 'pdf', urlApi)
  assert.equal(isNativeFileShareAvailable(prepared, {}), false)
  assert.equal(isNativeFileShareAvailable(prepared, { share() {}, canShare: () => false }), false)
  assert.equal(isNativeFileShareAvailable(prepared, { share() {}, canShare: ({ files }) => files.length === 1 }), typeof File !== 'undefined')
  if (typeof File !== 'undefined') {
    const file = makeSharedFile(prepared)
    assert.equal(file.name, prepared.filename)
    assert.equal(file.type, 'application/pdf')
  }
})

const panelPath=new URL('../src/pages/reports/roles/TuReportPreviewPanel.jsx',import.meta.url)
const pagePath=new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx',import.meta.url)
test('principal page uses authenticated hash export and never synthetic-clicks an anchor', async () => {
  const jsx=await readFile(panelPath,'utf8'), page=await readFile(pagePath,'utf8')
  assert.match(page,/<TuReportPreviewPanel[^>]*principal\//)
  assert.match(jsx,/apiClient\.download/)
  assert.match(jsx,/preview_hash:data\.preview_hash/)
  assert.match(jsx,/makePreparedDownload\(blob,/)
  assert.doesNotMatch(jsx,/\.click\(\)/)
  assert.match(jsx,/href=\{ready\.url\} download=\{ready\.filename\}/)
})

test('PDF offers a real open-link; share runs only on direct button click', async () => {
  const jsx=await readFile(panelPath,'utf8')
  assert.match(jsx,/target="_blank" rel="noreferrer"/)
  assert.match(jsx,/onClick=\{\(\)=>navigator\.share\(\{files:\[makeSharedFile\(ready\)\]/)
})

test('prepared files are invalidated by year, filter, report type, format, and reset', async () => {
  const jsx=await readFile(panelPath,'utf8'),page=await readFile(pagePath,'utf8')
  function check(source){assert.match(source,/JSON\.stringify\(\[type,params,enabled,principal\]\)/);assert.match(source,/<Preview key=\{key\}/);assert.match(source,/clearFile\(\);setFormat\(e\.target\.value\)/)}
  check(jsx);check(jsx.replace(/\r?\n/g,'\r\n'))
  assert.throws(()=>check(jsx.replace('<Preview key={key}','<Preview')))
  assert.match(page,/setType\(next\);setFilters\(emptyFilter\(\)\)/)
  assert.match(page,/setYearId\(next\);setFilters\(emptyFilter\(\)\)/)
  assert.match(page,/makePrincipalExportParams\(type,yearId,filters/)
})

test('object URLs are cleaned up, and message never pretends a download finished', async () => {
  const jsx=await readFile(panelPath,'utf8')
  assert.match(jsx,/URL\.revokeObjectURL\(url\.current\)/)
  assert.match(jsx,/useEffect\(\(\)=>\(\)=>\{generation\.current\+\+/)
  assert.doesNotMatch(jsx,/Unduhan .* dimulai/)
})

test('mobile ready actions are responsive and usable by touch and keyboard', async () => {
  const css=await readFile(new URL('../src/pages/reports/roles/tu-reports.css',import.meta.url),'utf8')
  const shared=await readFile(new URL('../src/pages/tu-monitoring/tu-monitoring.css',import.meta.url),'utf8')
  assert.match(css,/\.tur-preview \.tam-actions\{flex-wrap:wrap\}/)
  assert.match(css,/min-height:42px/)
  assert.match(shared,/@media\(max-width:600px\)/)
  assert.match(shared,/focus-visible/)
})

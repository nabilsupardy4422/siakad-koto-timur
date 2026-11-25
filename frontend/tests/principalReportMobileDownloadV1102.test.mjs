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

test('principal page calls authenticated report download but never synthetic-clicks an anchor', async () => {
  const jsx = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url), 'utf8')
  assert.match(jsx, /reportApi\.downloadReport\(type, params\)/)
  assert.match(jsx, /makePreparedDownload\(blob, filename, format\)/)
  assert.doesNotMatch(jsx, /\.click\(\)/)
  assert.match(jsx, /href=\{prepared\.url\} download=\{prepared\.filename\}/)
  assert.match(jsx, /Siapkan \$\{format === 'pdf'/)
})

test('PDF offers a real open-link; share runs only on direct button click', async () => {
  const jsx = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url), 'utf8')
  assert.match(jsx, /target="_blank" rel="noopener noreferrer"/)
  assert.match(jsx, /onClick=\{shareDocument\}/)
  assert.match(jsx, /navigator\.share\(\{ files: \[makeSharedFile\(prepared\)\]/)
})

// Check the actual handler boundaries, not a literal whitespace/line-ending sequence.
// Windows VS Code may save source as CRLF; both LF and CRLF must be equivalent.
function sourceBetween(source, start, end) {
  const from = source.indexOf(start)
  const to = source.indexOf(end, from + start.length)
  assert(from >= 0 && to > from, `Could not find ${start} handler`)
  return source.slice(from, to)
}

function assertInvalidatesBefore(source, expression, label) {
  assert.match(source, new RegExp(`discardPrepared\\(\\)\\s*;?\\s*${expression}`), `Missing prepared-file invalidation: ${label}`)
}

function checkInvalidationHandlers(jsx) {
  assertInvalidatesBefore(
    sourceBetween(jsx, 'const changeType = useCallback', 'const changeYear = useCallback'),
    'setType\\(next\\)', 'report type',
  )
  assertInvalidatesBefore(
    sourceBetween(jsx, 'const changeYear = useCallback', 'const setFilter ='),
    'setYearId\\(next\\)', 'academic period',
  )
  assertInvalidatesBefore(
    sourceBetween(jsx, 'const setFilter =', 'const fieldOptions ='),
    'setFilters\\(current\\s*=>', 'individual filter',
  )
  assertInvalidatesBefore(jsx, "setFormat\\('pdf'\\)", 'PDF selection')
  assertInvalidatesBefore(jsx, "setFormat\\('excel'\\)", 'Excel selection')
  assertInvalidatesBefore(jsx, 'setFilters\\(emptyFilter\\(\\)\\)', 'Reset filter')
}

test('prepared files are invalidated by year, filter, report type, format, and reset', async () => {
  const jsx = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url), 'utf8')
  checkInvalidationHandlers(jsx)
  // Regression: a change from LF to Windows CRLF must not break these checks.
  checkInvalidationHandlers(jsx.replace(/\r?\n/g, '\r\n'))
  // Removing the actual invalidation call must still cause this test to fail.
  const broken = jsx.replace(/discardPrepared\(\)\s*;?\s*setType\(next\)/, 'setType(next)')
  assert.throws(() => checkInvalidationHandlers(broken), /report type/)
})

test('object URLs are cleaned up, and message never pretends a download finished', async () => {
  const jsx = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.jsx', import.meta.url), 'utf8')
  assert.match(jsx, /URL\.revokeObjectURL\(previous\.url\)/)
  assert.match(jsx, /URL\.revokeObjectURL\(preparedRef\.current\.url\)/)
  assert.doesNotMatch(jsx, /Unduhan .* dimulai/)
})

test('mobile ready actions are responsive and usable by touch and keyboard', async () => {
  const css = await readFile(new URL('../src/pages/reports/roles/KepalaSekolahReports.css', import.meta.url), 'utf8')
  assert.match(css, /\.ks-report__ready-actions/)
  assert.match(css, /min-height: 44px/)
  assert.match(css, /@media\(max-width: 600px\)/)
  assert.match(css, /\.ks-report__ready-link:focus-visible/)
})

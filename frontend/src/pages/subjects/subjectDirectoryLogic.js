export const DEFAULT_SUBJECT_FILTERS = Object.freeze({ search: '', sort_order: 'asc', usage: '', kkm_state: '', page_size: 15 })
export const EMPTY_SUBJECT_FORM = Object.freeze({ kode: '', nama: '', kkm: '' })

export function subjectForm(subject) {
  return subject ? { kode: subject.kode, nama: subject.nama, kkm: subject.kkm == null ? '' : String(subject.kkm) } : { ...EMPTY_SUBJECT_FORM }
}

export function validateSubjectForm(form) {
  const errors = {}
  if (!form.kode.trim() || form.kode.trim().length > 50) errors.kode = 'Kode wajib diisi, maksimal 50 karakter.'
  if (!form.nama.trim() || form.nama.trim().length > 255) errors.nama = 'Nama wajib diisi, maksimal 255 karakter.'
  const kkm = form.kkm.trim()
  if (kkm !== '' && (!/^\d+(?:[.,]\d{1,2})?$/.test(kkm) || Number(kkm.replace(',', '.')) > 100)) {
    errors.kkm = 'Gunakan angka 0–100, maksimal dua desimal.'
  }
  return errors
}

export function subjectPayload(form, version) {
  return { kode: form.kode.trim(), nama: form.nama.trim(), kkm: form.kkm.trim() === '' ? null : form.kkm.trim().replace(',', '.'), ...(version ? { version } : {}) }
}

export function kkmView(value) {
  if (value == null || value === '') return { state: 'unset', label: 'Belum diisi' }
  const number = Number(value)
  if (!Number.isFinite(number) || number < 0 || number > 100) return { state: 'invalid', label: 'Perlu ditinjau', raw: String(value) }
  return { state: 'set', label: number.toLocaleString('id-ID', { maximumFractionDigits: 2 }) }
}

export function subjectDirectoryResponse(response, admin) {
  const { data, meta } = response || {}
  const validCount = n => Number.isInteger(n) && n >= 0
  if (!Array.isArray(data) || !meta || !validCount(meta.total) || !Number.isInteger(meta.current_page) || meta.current_page < 1 || !Number.isInteger(meta.last_page) || meta.last_page < 1 || !Number.isInteger(meta.per_page) || meta.per_page < 1) throw new Error('Respons direktori mata pelajaran tidak valid.')
  if (admin && (!meta.summary || !['total', 'used', 'unset', 'invalid'].every(k => validCount(meta.summary[k])) || data.some(row => !validCount(row.schedule_count) || typeof row.version !== 'string'))) throw new Error('Informasi penggunaan mata pelajaran tidak tersedia. Periksa versi backend.')
  return { rows: data, meta }
}

// Consumers of the small catalog must still handle more than one server page.
export async function loadSubjectCatalog(fetchPage) {
  const rows = [], ids = new Set()
  for (let page = 1; ; page++) {
    const result = await fetchPage(page)
    if (!Array.isArray(result?.data) || !Number.isInteger(result?.meta?.last_page) || result.meta.last_page < 1 || result.meta.current_page !== page) throw new Error('Respons katalog mata pelajaran tidak valid.')
    for (const row of result.data) {
      if (row?.id == null || ids.has(String(row.id))) throw new Error('Katalog berubah saat dimuat. Coba lagi.')
      ids.add(String(row.id)); rows.push(row)
    }
    if (page >= result.meta.last_page) {
      if (rows.length !== result.meta.total) throw new Error('Katalog berubah saat dimuat. Coba lagi.')
      return { data: rows, meta: { ...result.meta, current_page: 1, last_page: 1, total: rows.length, per_page: rows.length } }
    }
  }
}

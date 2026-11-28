export const CLASS_LEVELS = Object.freeze([[10, 'X'], [11, 'XI'], [12, 'XII']])
export const CLASS_FILTERS = Object.freeze({ search: '', grade_level: '', sort_order: 'asc', homeroom: '', schedule: '', page_size: 15 })
export const gradeLabel = level => CLASS_LEVELS.find(([n]) => n === Number(level))?.[1] || `Tingkat ${level ?? '—'}`
export const classLabel = row => `${gradeLabel(row.tingkat)} — ${row.nama}`
export const periodLabel = row => `${row.tahun_mulai}/${row.tahun_selesai} · ${row.semester === 'GANJIL' ? 'Ganjil' : row.semester === 'GENAP' ? 'Genap' : row.semester}`
export const activePeriod = years => {
  const active = years.filter(y => y.is_active === true || y.is_active === 1)
  return active.length === 1 ? String(active[0].id) : ''
}
export const classForm = (row, period = '') => ({ tahun_akademik_id: String(row?.tahun_akademik_id ?? period), tingkat: String(row?.tingkat ?? ''), nama: row?.nama ?? '' })
export function validateClassForm(form) {
  const errors = {}
  if (!/^\d+$/.test(form.tahun_akademik_id) || Number(form.tahun_akademik_id) < 1) errors.tahun_akademik_id = 'Pilih periode akademik.'
  if (!CLASS_LEVELS.some(([n]) => String(n) === form.tingkat)) errors.tingkat = 'Pilih tingkat X, XI, atau XII.'
  if (!form.nama.trim() || form.nama.trim().length > 100) errors.nama = 'Nama rombel wajib diisi, maksimal 100 karakter.'
  return errors
}
export const classPayload = (form, version) => ({ tahun_akademik_id: Number(form.tahun_akademik_id), tingkat: Number(form.tingkat), nama: form.nama.trim(), ...(version ? {version} : {}) })
const count = v => Number.isInteger(v) && v >= 0
export function validClassRow(row, canManage) {
  return !!row && row.id != null && row.tahun_akademik_id != null && typeof row.nama === 'string' && ['student_count','homeroom_count','schedule_count'].every(k=>count(row[k])) && Array.isArray(row.homeroom_names) && typeof row.locked === 'boolean' && row.history && ['members','homerooms','schedules'].every(k=>count(row.history[k])) && (!canManage || typeof row.version === 'string')
}
// Strict, separately testable detail contract; never reuse a stale list row.
export function classDetailResponse(response, id, period, canManage) {
  const row = response?.data
  if (!row || String(row.id) !== String(id) || String(row.tahun_akademik_id) !== String(period)) {
    throw new Error('Identitas kelas atau periode pada respons detail tidak sesuai.')
  }
  if (!validClassRow(row, canManage)) {
    throw new Error('Metadata detail kelas tidak lengkap (jumlah, relasi, atau versi). Periksa respons API dan versi backend.')
  }
  if (!row.context || String(row.context.id) !== String(period) || !['effective','history'].includes(row.context.mode)) {
    throw new Error('Konteks periode pada detail kelas tidak lengkap. Periksa respons API dan versi backend.')
  }
  return row
}
// The global error translator was originally written for grade components.
// Prefer class-specific Laravel validation messages, with safe legacy fallback.
export function classMutationError(error) {
  const raw = error?.payload?.errors ?? error?.response?.data?.errors ?? error?.validationErrors ?? error?.errors ?? {}
  const fields = Object.fromEntries(Object.entries(raw).map(([field, value]) => [field, (Array.isArray(value) ? value : [value]).map(message => {
    const text = String(message || '')
    if (field !== 'nama') return text
    if (/already been taken|nama komponen sudah digunakan/i.test(text)) return 'Nama rombel sudah digunakan pada tingkat dan periode ini.'
    if (/is required|nama komponen wajib/i.test(text)) return 'Nama rombel wajib diisi.'
    if (/greater than 100|nama komponen maksimal/i.test(text)) return 'Nama rombel maksimal 100 karakter.'
    return text
  })]))
  const first = Object.values(fields).flat().find(Boolean)
  return { fields, message: error?.status === 422 && first ? first : error?.message || 'Terjadi kesalahan. Silakan coba lagi.' }
}
export function directoryResponse(response, period, canManage) {
  const {data,meta}=response || {}
  if (!Array.isArray(data) || !meta || !['total','current_page','last_page','per_page'].every(k=>count(meta[k])) || meta.last_page < 1 || meta.current_page < 1 || !meta.context || String(meta.context.id)!==String(period) || !['effective','history'].includes(meta.context.mode) || !meta.summary || !['total','students','without_homeroom','without_schedule'].every(k=>count(meta.summary[k])) || !data.every(r=>validClassRow(r,canManage)&&String(r.tahun_akademik_id)===String(period))) throw new Error('Respons direktori kelas tidak lengkap. Periksa versi backend.')
  return {rows:data,meta}
}
export function classTarget(row, path) {
  return `${path}?${new URLSearchParams({academic_year_id:String(row.tahun_akademik_id),class_id:String(row.id)})}`
}
export function readClassTarget(search) {
  const params = new URLSearchParams(search)
  const id = k => /^\d+$/.test(params.get(k)||'') && Number(params.get(k))>0 ? params.get(k) : ''
  const year = id('academic_year_id'), cls = id('class_id')
  return year && cls ? {academic_year_id:year,class_id:cls} : {academic_year_id:'',class_id:''}
}
export async function loadClassCatalog(fetchPage) {
  const rows=[],ids=new Set()
  for(let page=1;;page++) {
    const response=await fetchPage(page), meta=response?.meta
    if(!Array.isArray(response?.data)||!meta||meta.current_page!==page||!Number.isInteger(meta.last_page)||meta.last_page<1||!count(meta.total)) throw new Error('Respons katalog kelas tidak valid.')
    for(const row of response.data){if(row.id==null||ids.has(String(row.id)))throw new Error('Katalog kelas berubah saat dimuat. Coba lagi.');ids.add(String(row.id));rows.push(row)}
    if(page>=meta.last_page){if(rows.length!==meta.total)throw new Error('Katalog kelas berubah saat dimuat. Coba lagi.');return {data:rows,meta:{...meta,current_page:1,last_page:1,total:rows.length,per_page:rows.length}}}
  }
}

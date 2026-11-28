export const BATCH_LIMIT = 100
export const isAcademic = user => ['GURU','SISWA'].includes(user?.role?.code)
const validId = id => Number.isSafeInteger(id) && id > 0
export function mergeSelection(previous, added) {
  const next = [...new Set([...previous,...added])]
  if (next.some(id=>!validId(id)) || next.length>BATCH_LIMIT) throw new Error('Maksimal 100 akun per batch.')
  return next
}
export function accountTotals(response) {
  const d=response?.data
  const keys=['total','ready','pending','inactive','unknown']
  if (!d || keys.some(k=>!Number.isSafeInteger(d[k]) || d[k]<0) || d.ready+d.pending+d.inactive+d.unknown!==d.total) return null
  return Object.fromEntries(keys.map(k=>[k,d[k]]))
}
export function filteredSnapshot(response, expectedTotal) {
  if (!Number.isSafeInteger(expectedTotal) || expectedTotal<1 || expectedTotal>BATCH_LIMIT || response?.meta?.total!==expectedTotal || response?.meta?.current_page!==1 || response?.meta?.last_page!==1 || !Array.isArray(response?.data) || response.data.length!==expectedTotal) throw new Error('Hasil filter berubah atau melebihi 100. Muat ulang daftar, atau pilih per halaman.')
  const ids=response.data.map(row=>row.id)
  if (ids.some(id=>!validId(id)) || new Set(ids).size!==ids.length) throw new Error('Identitas akun tidak valid.')
  return {ids:response.data.filter(isAcademic).map(row=>row.id),excluded:response.data.filter(row=>!isAcademic(row)).length}
}
export function validateBulkPreview(response, action, ids) {
  const d=response?.data
  const keys=Array.isArray(d?.rows)?d.rows.map(r=>r.user_id):[]
  if (!d || d.action!==action || d.total!==ids.length || keys.length!==ids.length || new Set(keys).size!==keys.length || !ids.every(id=>keys.includes(id)) || d.rows.some(r=>typeof r.eligible!=='boolean' || typeof r.has_active_code!=='boolean') || d.eligible!==d.rows.filter(r=>r.eligible).length || d.reissue_count!==(action==='issue'?d.rows.filter(r=>r.eligible&&r.has_active_code).length:0) || !Number.isSafeInteger(d.expires) || !/^[a-f0-9]{32}$/.test(d.preview_nonce||'') || !/^[a-f0-9]{64}$/.test(d.preview_token||'')) throw new Error('Respons preview tidak valid. Jangan lanjutkan.')
  return d
}
export function bulkPayload(preview, ids, confirmed, reissue) {
  if (!confirmed || !preview || preview.total!==ids.length || preview.eligible!==ids.length || !ids.every(id=>preview.rows.some(r=>r.user_id===id&&r.eligible)) || (preview.reissue_count>0&&!reissue)) throw new Error('Periksa dan konfirmasi semua akun dahulu.')
  return {user_ids:ids,expires:preview.expires,preview_token:preview.preview_token,preview_nonce:preview.preview_nonce,confirm:true,confirm_reissue:!!reissue}
}

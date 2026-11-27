export function jakartaDate(now = new Date()) {
  const parts = new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Jakarta', year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(now)
  const get = type => parts.find(p => p.type === type)?.value
  return `${get('year')}-${get('month')}-${get('day')}`
}
export function initialClassContext(years, classes, target) {
  if (target.class_id) {
    const match = classes.find(c=>String(c.id)===target.class_id && String(c.tahun_akademik_id)===target.academic_year_id)
    return {yearId:target.academic_year_id, classId:match?String(match.id):'', missing:!match}
  }
  const active=years.filter(y=>y.is_active===true || y.is_active===1)
  const yearId=active.length===1?String(active[0].id):''
  return {yearId, classId:yearId?String(classes.find(c=>String(c.tahun_akademik_id)===yearId)?.id??''):'', missing:false}
}
export function membershipStatus(row, asOf) {
  if (!asOf || !row.tanggal_mulai || (row.tanggal_selesai && row.tanggal_selesai < row.tanggal_mulai)) return 'Tidak valid'
  if (row.tanggal_mulai > asOf) return 'Terjadwal'
  if (row.tanggal_selesai && row.tanggal_selesai < asOf) return 'Riwayat'
  return 'Efektif pada tanggal acuan'
}
export function canCommitPreview(preview) {
  return typeof preview?.preview_token==='string' && preview.preview_token.length>0 && Array.isArray(preview.rows) && preview.rows.length>0 && preview.rows.length<=100 && preview.rows.every(r=>r.valid===true)
}

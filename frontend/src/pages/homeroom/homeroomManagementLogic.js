export const statusLabels = { effective: 'Berlaku', scheduled: 'Terjadwal', unassigned: 'Belum ditugaskan', conflict: 'Konflik', history: 'Selesai', cancelled: 'Dibatalkan' }
export const statusVariants = { effective: 'success', scheduled: 'info', unassigned: 'neutral', conflict: 'danger', history: 'neutral', cancelled: 'warning' }
export const accountLabels = { ready: 'Siap', pending: 'Belum aktivasi', inactive: 'Nonaktif' }
export const yearLabel = year => `${year.tahun_mulai}/${year.tahun_selesai} — ${year.semester}`
export const classLabel = cls => `${({10:'X',11:'XI',12:'XII'})[cls.tingkat] || cls.tingkat} · ${cls.nama}`
export const dateLabel = value => value ? String(value).slice(0,10).split('-').reverse().join('/') : '—'
export function workspaceData(response, year) {
  const data = response?.data
  if (!data || String(data.period_id) !== String(year) || !/^\d{4}-\d{2}-\d{2}$/.test(data.as_of) || !Array.isArray(data.classes) || !Array.isArray(data.assignments) || !['active','inactive','none','conflict'].includes(data.active_state)) throw new Error('Respons workspace tidak lengkap. Periksa versi backend.')
  const ids = new Set(data.assignments.map(a => String(a.record?.id)))
  const classes = new Set(data.classes.map(c => String(c.id)))
  if (ids.size !== data.assignments.length || classes.size !== data.classes.length || data.assignments.some(a => !a.record?.id || !a.record.version || !['effective','scheduled','history','conflict','cancelled'].includes(a.status)) || data.classes.some(c => !c.id || !['effective','scheduled','unassigned','conflict'].includes(c.status) || !Array.isArray(c.assignment_ids) || c.assignment_ids.some(id=>!ids.has(String(id))))) throw new Error('Pemetaan penugasan tidak konsisten. Muat ulang.')
  if (data.summary?.total !== data.classes.length || ['effective','scheduled','unassigned','conflict'].some(s => data.summary[s] !== data.classes.filter(c=>c.status===s).length)) throw new Error('Ringkasan rombel tidak konsisten. Muat ulang.')
  return data
}
export function filterAssignments(rows, {tab, classId, status, search}) {
  const term = search.trim().toLocaleLowerCase('id')
  return rows.filter(({record:r,status:s}) => (tab === 'history' ? ['history','conflict','cancelled'].includes(s) : !['history','cancelled'].includes(s)) && (!classId || String(r.kelas_id)===String(classId)) && (!status || s===status) && (!term || [r.guru?.nama_lengkap,r.guru?.nip,r.kelas?.nama,r.kelas?classLabel(r.kelas):''].some(v=>String(v??'').toLocaleLowerCase('id').includes(term))))
}
export function paginate(rows, page, size = 15) {
  const last = Math.max(1, Math.ceil(rows.length / size)), current = Math.max(1,Math.min(page,last))
  return {current,last,total:rows.length,rows:rows.slice((current-1)*size,current*size)}
}

export function continuationTargets(source, years = []) {
  return source?.semester === 'GANJIL' ? years.filter(y=>y.semester==='GENAP' && String(y.tahun_mulai)===String(source.tahun_mulai) && String(y.tahun_selesai)===String(source.tahun_selesai) && String(y.id)!==String(source.id)) : []
}
export function suggestMappings(rows, classes) {
  const result={}, used=new Set()
  for(const {record:r,account_state} of rows){
    if(account_state==='inactive'||account_state==='unknown')continue
    const matches=classes.filter(c=>c.status==='unassigned'&&String(c.tingkat)===String(r.kelas?.tingkat)&&c.nama===r.kelas?.nama)
    if(matches.length===1&&!used.has(String(matches[0].id))){result[r.id]=String(matches[0].id);used.add(String(matches[0].id))}
  }
  return result
}

export function eligibleCandidates(response) {
  if (!Array.isArray(response?.data)) throw new Error('Daftar calon wali tidak valid.')
  const seen=new Set()
  return response.data.filter(g=>{
    if(!g?.id||seen.has(String(g.id))||typeof g.nama_lengkap!=='string')throw new Error('Identitas kandidat tidak konsisten.')
    seen.add(String(g.id))
    return ['ready','pending'].includes(g.account_state)
  })
}

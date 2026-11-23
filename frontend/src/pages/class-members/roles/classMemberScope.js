import { collectPages, sameId } from '../../../utils/teachingScope.js'
import { recapParams } from '../../homeroom/homeroomScope.js'
export function schoolDay(now = new Date()) {
  return new Intl.DateTimeFormat('en-CA', {timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit'}).format(now)
}
const validDate = v => typeof v === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(v) && !Number.isNaN(Date.parse(v)) && new Date(v).toISOString().slice(0,10)===v
export function memberRows(rows, classId, today = schoolDay()) {
  const active = new Set()
  return rows.map(r=>{
    if (!sameId(r?.kelas_id,classId) || !sameId(r?.siswa_id,r?.siswa?.id) || r?.id==null || !validDate(r.tanggal_mulai) || (r.tanggal_selesai!==null&&(!validDate(r.tanggal_selesai)||r.tanggal_selesai<r.tanggal_mulai))) throw new Error('Data keanggotaan tidak valid atau di luar kelas binaan.')
    const status = r.tanggal_mulai>today?'scheduled':r.tanggal_selesai!==null&&r.tanggal_selesai<today?'history':'active'
    if(status==='active'){if(active.has(String(r.siswa_id)))throw new Error('Keanggotaan aktif ganda. Hubungi Tata Usaha.');active.add(String(r.siswa_id))}
    return {id:r.id,name:r.siswa.nama_lengkap||r.siswa.user?.name||'Nama belum tersedia',nis:r.siswa.nis||'',nisn:r.siswa.nisn||'',start:r.tanggal_mulai,end:r.tanggal_selesai,status}
  }).sort((a,b)=>a.name.localeCompare(b.name,'id')||b.start.localeCompare(a.start))
}
export async function loadClassMembers(api,assignment,year,guru){
  if (![true,1,'1'].includes(year?.is_active)) throw new Error('Periode akademik tidak aktif.')
  const p=recapParams(assignment,year,guru)
  let asOf=null
  const rows=await collectPages(async page=>{
    const response=await api.getAll(p.class_id,{page,per_page:100,scope:'homeroom',academic_year_id:year.id,membership_status:'all'})
    const date=response?.meta?.as_of
    if(!validDate(date)||(asOf!==null&&asOf!==date)) throw new Error('Tanggal acuan server berubah atau tidak tersedia. Muat ulang data.')
    asOf=date
    return response
  })
  return {rows:memberRows(rows,p.class_id,asOf),asOf}
}

import { collectPages, sameId } from '../../utils/teachingScope.js'

const params = page => ({ page, per_page: 100 })
const validId = id => /^\d+$/.test(String(id)) && Number(id) > 0
const dateOnly = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
export const currentDay = () => new Date().toISOString().slice(0, 10)
export function activeAssignments(records, guruId, yearId, today = currentDay()) {
  return records.filter(a => validId(a?.id) && validId(a.kelas_id) && sameId(a.guru_id, guruId) &&
    sameId(a.tahun_akademik_id, yearId) && sameId(a.kelas?.id, a.kelas_id) &&
    sameId(a.tahun_akademik?.id, yearId) && dateOnly(a.tanggal_mulai) && a.tanggal_mulai <= today && a.tanggal_selesai === null)
}
export async function loadHomeroomContext({ academicYearApi, homeroomApi }, guruId) {
  if (!validId(guruId)) throw new Error('Identitas Guru belum tersedia.')
  const years = await collectPages(page => academicYearApi.getAll(params(page)))
  const active = years.filter(y => [true, 1, '1'].includes(y.is_active))
  if (active.length > 1) throw new Error('Lebih dari satu periode aktif. Hubungi Tata Usaha.')
  const year = active[0] || null
  if (!year) return { year, assignments: [] }
  if (!validId(year.id) || !['GANJIL','GENAP'].includes(year.semester)) throw new Error('Periode akademik tidak valid.')
  const assignments = await collectPages(page => homeroomApi.getAll({ ...params(page), scope:'homeroom', teacher_id: guruId, academic_year_id:year.id }))
  return { year, assignments: activeAssignments(assignments, guruId, year.id) }
}
export function recapParams(assignment, year, guruId) {
  if (!activeAssignments([assignment], guruId, year?.id).length) throw new Error('Penugasan tidak berlaku. Muat ulang konteks kelas.')
  return { scope:'homeroom', class_id:assignment.kelas_id, academic_year_id:year.id, semester:year.semester }
}
export function roster(records, classId, today = currentDay()) {
  const students = new Map()
  for (const row of records) {
    if (!sameId(row?.kelas_id, classId) || !validId(row.siswa_id) || !sameId(row.siswa_id,row.siswa?.id)) throw new Error('Data anggota kelas tidak sesuai konteks.')
    if (!dateOnly(row.tanggal_mulai) || row.tanggal_mulai > today || (row.tanggal_selesai !== null && (!dateOnly(row.tanggal_selesai) || row.tanggal_selesai < today))) continue
    if (students.has(String(row.siswa_id))) throw new Error('Keanggotaan siswa ganda. Hubungi Tata Usaha.')
    students.set(String(row.siswa_id), { id:row.siswa_id, name:row.siswa.nama_lengkap || row.siswa.user?.name || 'Nama belum tersedia', nis:row.siswa.nis || '', nisn:row.siswa.nisn || '' })
  }
  return [...students.values()].sort((a,b)=>a.name.localeCompare(b.name,'id'))
}
function number(value, label, integer = false) {
  if (value === null || value === undefined || value === '' || !['number','string'].includes(typeof value)) throw new Error(`Data ${label} tidak valid.`)
  const n=Number(value)
  if (!Number.isFinite(n) || n<0 || (integer && !Number.isInteger(n))) throw new Error(`Data ${label} tidak valid.`)
  return n
}
export function attendanceSummary(response) {
  const d=response?.data
  const out=Object.fromEntries(['total','hadir','izin','sakit','alpa'].map(k=>[k,number(d?.[k],k,true)]))
  if (out.hadir+out.izin+out.sakit+out.alpa!==out.total) throw new Error('Rekap presensi tidak konsisten.')
  return {...out,percent:out.total?out.hadir/out.total*100:null}
}
export function gradeSummary(response) {
  const d=response?.data, total=number(d?.total,'jumlah nilai',true)
  if(!total) return {total,average:null,high:null,low:null}
  const average=number(d.rata_rata,'rata-rata'), high=number(d.tertinggi,'tertinggi'), low=number(d.terendah,'terendah')
  if(high>100 || low>average || average>high) throw new Error('Rekap nilai tidak konsisten.')
  return {total,average,high,low}
}
export async function loadHomeroomData({classMemberApi,attendanceApi,gradeApi}, assignment, year, guruId) {
  const context=recapParams(assignment,year,guruId)
  const result=await Promise.allSettled([
    collectPages(page=>classMemberApi.getAll(context.class_id,{...params(page),scope:context.scope,academic_year_id:context.academic_year_id})).then(rows=>roster(rows,context.class_id)),
    Promise.resolve().then(()=>attendanceApi.getRecap(context)).then(attendanceSummary),
    Promise.resolve().then(()=>gradeApi.getRecap(context)).then(gradeSummary),
  ])
  // A revoked assignment must not leave partial data on screen.
  if(result.some(r=>r.status==='rejected' && [401,403,404].includes(r.reason?.status))) throw new Error('Akses kelas tidak lagi tersedia. Muat ulang penugasan Anda.')
  return Object.fromEntries(['students','attendance','grades'].map((key,i)=>[key,result[i].status==='fulfilled'?{data:result[i].value,error:''}:{data:null,error:result[i].reason?.message||'Data gagal dimuat.'}]))
}

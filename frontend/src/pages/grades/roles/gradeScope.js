import { collectPages, ownTeachingSchedules, sameId } from '../../../utils/teachingScope.js'

export const idOf = value => value == null ? '' : String(value)
export const same = sameId
export const allPages = collectPages
export const classId = s => s?.kelas_id ?? s?.kelas?.id
export const ownSchedules = (list,guru,year) => ownTeachingSchedules(list,guru,year).filter(s=>classId(s)!=null)
export const gradeParams = (year,schedule,extra={}) => ({...extra,scope:'teaching',academic_year_id:year,schedule_id:schedule})
const matchesSchedule = (actual,expected) => same(actual?.id,expected?.id) && same(actual?.guru_id,expected?.guru_id ?? expected?.guru?.id) && same(actual?.tahun_akademik_id,expected?.tahun_akademik_id ?? expected?.tahun_akademik?.id) && same(actual?.kelas_id,classId(expected)) && same(actual?.mapel_id,expected?.mapel_id ?? expected?.mapel?.id)
export const ownComponents = (list,schedule) => list.filter(c=>c?.id!=null && same(c.jadwal_pelajaran_id,schedule?.id) && matchesSchedule(c.jadwal_pelajaran,schedule))
// Dates are school calendar dates in Asia/Jakarta, not UTC.
import {teacherDay} from '../../../utils/teacherDay.js'
export function activeStudents(members,kelas,date=teacherDay()) {
 const map=new Map()
 for(const m of members){
  if(!same(m.kelas_id,kelas)||typeof m.tanggal_mulai!=='string'||m.tanggal_mulai>date||(m.tanggal_selesai!=null&&m.tanggal_selesai<date)||m.siswa?.id==null||!same(m.siswa_id,m.siswa.id))continue
  const key=idOf(m.siswa.id);if(map.has(key))throw new Error('Keanggotaan ganda terdeteksi. Hubungi Tata Usaha.');map.set(key,m.siswa)
 }
 return [...map.values()]
}
export const scopedGrades = (grades,component,students,schedule) => grades.filter(g=>g?.id!=null && same(g.komponen_nilai_id,component) && same(g.komponen_nilai?.id,component) && matchesSchedule(g.komponen_nilai?.jadwal_pelajaran,schedule) && students.some(s=>same(s.id,g.siswa_id)))
export function buildRows(students,grades,component,schedule) {
  const map=new Map()
  for(const grade of scopedGrades(grades,component,students,schedule)) {
    const key=idOf(grade.siswa_id)
    if(map.has(key)) throw new Error('Nilai ganda terdeteksi. Hubungi Tata Usaha sebelum melanjutkan.')
    map.set(key,grade)
  }
  return students.map(s=>makeRow(s,map.get(idOf(s.id))))
}
export const patchStudentRow = (rows,id,patch) => rows.map(r=>same(r.student.id,id)?{...r,...patch}:r)
export const valueText = v => v==null?'':String(v)
export const parseDecimal = v => /^\d+(?:[.,]\d+)?$/.test(String(v).trim()) ? Number(String(v).trim().replace(',','.')) : NaN
export const validScore = v => Number.isFinite(parseDecimal(v)) && parseDecimal(v)>=0 && parseDecimal(v)<=100
export function makeRow(student,grade) {
  const nilai=valueText(grade?.nilai), catatan=valueText(grade?.catatan)
  return {student,gradeId:grade?.id??null,nilai,catatan,original:{nilai,catatan},error:'',uncertain:false}
}
export const dirty = row => row.nilai!==row.original.nilai || row.catatan!==row.original.catatan
export function errorText(e) {
  const first=Object.values(e?.validationErrors||{}).flat().find(Boolean)
  if(first)return String(first)
  if(e?.status===409)return e?.message||'Data masih digunakan oleh catatan lain.'
  if(e?.status===403)return 'Anda tidak memiliki izin untuk tindakan ini.'
  if(e?.status===401)return 'Sesi berakhir. Silakan masuk kembali.'
  return e?.message||'Terjadi kesalahan. Coba lagi.'
}

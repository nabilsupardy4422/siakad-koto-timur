export const TYPES = [
  ['students','users','Daftar siswa','Daftar anggota aktif'],
  ['schedules','calendar','Jadwal mengajar','Hari dan jam pelajaran'],
  ['attendance','check','Presensi','Catatan per pertemuan'],
  ['grades','chart','Penilaian','Nilai setiap komponen'],
  ['semester-recap','file','Rekap semester','Ringkasan kelas binaan'],
]
export const permittedTypes = scope => scope === 'homeroom' ? TYPES.map(t=>t[0]) : TYPES.slice(0,4).map(t=>t[0])
export const blankFilters = (scope, context) => ({ class_id:scope==='homeroom'&&context?.homeroom?.classes?.length===1?String(context.homeroom.classes[0].id):'', subject_id:'', schedule_id:'', search:'', date_from:'', date_to:'' })
const eq=(a,b)=>String(a)===String(b)
export function filterOptions(context,scope,filters) {
  const data=context?.[scope] || {classes:[],schedules:[]}
  const classes=data.classes
  const schedules=data.schedules.filter(s=>classes.some(c=>eq(c.id,s.class_id)) && (!filters.class_id||eq(s.class_id,filters.class_id)))
  const subjects=[...new Map(schedules.map(s=>[String(s.subject_id),{id:s.subject_id,label:s.subject}])).values()]
  return {classes,subjects,schedules:schedules.filter(s=>!filters.subject_id||eq(s.subject_id,filters.subject_id))}
}
export function reportParams(context,scope,type,filters) {
  if (!context?.year?.id || !['teaching','homeroom'].includes(scope) || !permittedTypes(scope).includes(type)) throw new Error('Konteks laporan tidak tersedia.')
  const {classes,subjects,schedules}=filterOptions(context,scope,filters)
  if(scope==='homeroom' && !filters.class_id) throw new Error('Pilih kelas binaan.')
  if(filters.class_id&&!classes.some(c=>eq(c.id,filters.class_id))) throw new Error('Kelas tidak sesuai penugasan.')
  const out={scope,academic_year_id:context.year.id}
  if(filters.class_id) out.class_id=filters.class_id
  if(type!=='semester-recap') {
    if(filters.subject_id) {if(!subjects.some(s=>eq(s.id,filters.subject_id))) throw new Error('Mata pelajaran tidak sesuai penugasan.');out.subject_id=filters.subject_id}
    if(filters.schedule_id) {if(!schedules.some(s=>eq(s.id,filters.schedule_id))) throw new Error('Jadwal tidak sesuai penugasan.');out.schedule_id=filters.schedule_id}
  }
  if(type==='students'&&filters.search.trim())out.search=filters.search.trim()
  if(type==='attendance') {
    if(filters.date_from&&filters.date_to&&filters.date_from>filters.date_to)throw new Error('Tanggal selesai tidak boleh sebelum tanggal mulai.')
    for(const key of ['date_from','date_to'])if(filters[key])out[key]=filters[key]
  }
  return out
}
export function validateContext(value) {
  if(!value || (value.year!==null && (!value.year?.id||typeof value.year.label!=='string')))throw new Error('Periode dari server tidak valid.')
  for(const scope of ['teaching','homeroom']) {
    const d=value[scope]
    if(!Array.isArray(d?.classes)||!Array.isArray(d?.schedules)||d.classes.some(c=>!c.id||!c.label)||d.schedules.some(s=>!s.id||!s.subject_id||!s.subject||!d.classes.some(c=>eq(c.id,s.class_id))))throw new Error('Konteks penugasan dari server tidak valid.')
  }
  return value
}
export function validatePreview(data,page) {
  if(!data?.metadata || !Array.isArray(data.headings)||!Array.isArray(data.rows)||!Number.isInteger(data.total)||data.total<0||data.page!==page||!Number.isInteger(data.last_page)||data.last_page<page||data.per_page!==8||data.rows.length!==Math.min(8,Math.max(0,data.total-(page-1)*8))||!(/^[a-f0-9]{64}$/).test(data.preview_hash)) throw new Error('Pratinjau dari server tidak valid.')
  return data
}

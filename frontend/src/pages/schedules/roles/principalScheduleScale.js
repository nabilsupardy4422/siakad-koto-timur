import { PRINCIPAL_SCHEDULE_DAYS, principalScheduleClassLabel, principalScheduleOptions, sortPrincipalWeekly } from './principalScheduleMonitoring.js'

export const CLASS_PAGE_SIZE = 30
export const TIME_PAGE_SIZE = 20
export const LIST_PAGE_SIZE = 25
export const GROUP_PAGE_SIZE = 50
export function scheduleTime(value) {
  const [h,m,s=0] = value.split(':').map(Number)
  return h*3600+m*60+s
}
export function displayTime(value) { return value.length > 5 && value.slice(6) !== '00' ? value : value.slice(0,5) }
export function dayLabel(day) { return day[0]+day.slice(1).toLowerCase() }
export function scheduleTitle(row) { return row.mapel?.nama || row.mapel?.kode || `Mapel #${row.mapel_id}` }
export function teacherTitle(row) { return row.guru?.nama_lengkap || `Guru #${row.guru_id}` }
export function pageSlice(rows, requested, size) {
  const last = Math.max(1,Math.ceil(rows.length/size))
  const page = Math.max(1,Math.min(last, requested))
  const offset=(page-1)*size
  return {rows:rows.slice(offset,offset+size),page,last,first:rows.length?offset+1:0,end:Math.min(offset+size,rows.length),total:rows.length}
}
export function initialMatrixDay(rows, preferred='') {
  return preferred || PRINCIPAL_SCHEDULE_DAYS.find(day=>rows.some(row=>row.hari===day)) || 'SENIN'
}
// Rows represent exact start times, not uniform teaching periods. Each record occurs once.
export function buildPrincipalMatrix(rows, day) {
  const classes=principalScheduleOptions(rows,row=>row.kelas_id,principalScheduleClassLabel).sort((a,b)=>a.label.localeCompare(b.label,'id',{numeric:true})||Number(a.value)-Number(b.value))
  const slots=new Map()
  let count=0
  for(const row of sortPrincipalWeekly(rows.filter(x=>x.hari===day))) {
    const start=scheduleTime(row.jam_mulai)
    if(!slots.has(start)) slots.set(start,{start,time:displayTime(row.jam_mulai),cells:new Map()})
    const cells=slots.get(start).cells, key=String(row.kelas_id)
    if(!cells.has(key)) cells.set(key,[])
    cells.get(key).push(row); count++
  }
  return {classes,slots:[...slots.values()].sort((a,b)=>a.start-b.start),count}
}
export function searchPrincipalSchedule(rows, query) {
  const q=query.trim().toLocaleLowerCase('id')
  return sortPrincipalWeekly(rows.filter(row=>!q||[dayLabel(row.hari),principalScheduleClassLabel(row),scheduleTitle(row),teacherTitle(row),displayTime(row.jam_mulai),displayTime(row.jam_selesai)].some(x=>x.toLocaleLowerCase('id').includes(q))))
}
// Merge visually overlapping rectangles, including minimum card height. No ID lost.
// Use seconds for KS only; legacy calendar geometry for other roles is untouched.
export function principalCalendarGroups(rows, startMinute, hourHeight, minHeight=42) {
  const items=rows.map(row=>{
    const top=(scheduleTime(row.jam_mulai)/60-startMinute)/60*hourHeight
    const height=Math.max(minHeight,(scheduleTime(row.jam_selesai)-scheduleTime(row.jam_mulai))/3600*hourHeight)
    return {row,top,bottom:top+height}
  }).sort((a,b)=>a.top-b.top||a.row.id-b.row.id)
  const groups=[]
  for(const item of items){
    const last=groups.at(-1)
    if(last && item.top<last.bottom){last.rows.push(item.row);last.bottom=Math.max(last.bottom,item.bottom)}
    else groups.push({top:item.top,bottom:item.bottom,rows:[item.row]})
  }
  return groups
}

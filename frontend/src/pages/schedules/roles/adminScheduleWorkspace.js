// TU-only read projection. Existing write API and authorization remain unchanged.
import {parsePrincipalSchedulePage, combinePrincipalSchedulePages, filterPrincipalSchedules, sortPrincipalWeekly} from './principalScheduleMonitoring.js'
export const DAYS = ['SENIN','SELASA','RABU','KAMIS','JUMAT','SABTU','MINGGU']
export const isActive = row => [true,1,'1'].includes(row?.is_active)
export async function loadScheduleSnapshot(fetchPage, period, semester) {
  if (!period || !['GANJIL','GENAP'].includes(semester)) throw new Error('Pilih periode akademik yang valid.')
  const pages=[]
  for(let page=1;;page++) {
    const parsed=parsePrincipalSchedulePage(await fetchPage({academic_year_id:period,semester,page,per_page:100}),period,page)
    if(parsed.rows.some(r=>r.tahun_akademik?.semester!==semester || !r.kelas || !r.guru || !r.mapel || !r.version)) throw new Error('Konteks atau versi jadwal tidak lengkap.')
    if(page>1 && (parsed.meta.last!==pages[0].meta.last || parsed.meta.total!==pages[0].meta.total || parsed.meta.size!==pages[0].meta.size)) throw new Error('Data jadwal berubah saat dimuat. Muat ulang.')
    pages.push(parsed)
    if(page>=parsed.meta.last) break
    if(page>=1000) throw new Error('Jumlah halaman melebihi batas pemeriksaan.')
  }
  return sortPrincipalWeekly(combinePrincipalSchedulePages(pages))
}
export function filterRows(rows, filters) {return sortPrincipalWeekly(filterPrincipalSchedules(rows,filters))}
export function minutes(time) {const [h,m,s=0]=time.split(':').map(Number);return h*60+m+s/60}
export function duration(row) {return minutes(row.jam_selesai)-minutes(row.jam_mulai)}
export function scheduleSummary(rows, classes) {
  const scheduled=new Set(rows.map(r=>String(r.kelas_id)))
  const conflict=new Set()
  for(const key of ['guru_id','kelas_id']) {
    const groups=new Map()
    for(const row of rows) {const k=`${row.hari}:${row[key]}`; if(!groups.has(k)) groups.set(k,[]);groups.get(k).push(row)}
    for(const list of groups.values()) {
      const ordered=[...list].sort((a,b)=>minutes(a.jam_mulai)-minutes(b.jam_mulai));let active=[]
      for(const row of ordered) {active=active.filter(prev=>minutes(prev.jam_selesai)>minutes(row.jam_mulai));if(active.length){conflict.add(row.id);active.forEach(prev=>conflict.add(prev.id))}active.push(row)}
    }
  }
  return {total:rows.length,teachers:new Set(rows.map(r=>String(r.guru_id))).size,classes:scheduled.size,subjects:new Set(rows.map(r=>String(r.mapel_id))).size,empty:classes.filter(r=>!scheduled.has(String(r.id))).length,conflicts:conflict.size}
}
export function teacherDistribution(rows) {
  const groups=new Map()
  for(const r of rows) {const id=String(r.guru_id);if(!groups.has(id))groups.set(id,{id,name:r.guru.nama_lengkap,rows:0,minutes:0,classes:new Set(),subjects:new Set()});const g=groups.get(id);g.rows++;g.minutes+=duration(r);g.classes.add(String(r.kelas_id));g.subjects.add(String(r.mapel_id))}
  return [...groups.values()].map(g=>({...g,classes:g.classes.size,subjects:g.subjects.size})).sort((a,b)=>a.name.localeCompare(b.name,'id')||Number(a.id)-Number(b.id))
}
export function pickerPage(options, query, page, size=5) {
  const q=query.trim().toLocaleLowerCase('id');const rows=options.filter(o=>`${o.label} ${o.description||''}`.toLocaleLowerCase('id').includes(q));const last=Math.max(1,Math.ceil(rows.length/size));const current=Math.min(last,Math.max(1,page));return {rows:rows.slice((current-1)*size,current*size),total:rows.length,page:current,last}
}

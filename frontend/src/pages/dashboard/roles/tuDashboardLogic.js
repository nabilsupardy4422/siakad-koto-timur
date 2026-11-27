import {monitoringFrom} from '../../tu-monitoring/monitoringLogic.js'
import {parseWorkspace} from '../../report-cards/roles/tuReportCardWorkspace.js'
const fail=()=>{throw new Error('Data dashboard tidak konsisten atau konteks berubah. Muat ulang.')}
const count=x=>Number.isSafeInteger(x)&&x>=0
const id=x=>Number.isSafeInteger(x)&&x>0
const date=x=>typeof x==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(x)&&!Number.isNaN(Date.parse(x))&&new Date(x).toISOString().slice(0,10)===x
const period=x=>x&&id(x.id)&&Number.isInteger(x.tahun_mulai)&&x.tahun_selesai===x.tahun_mulai+1&&['GANJIL','GENAP'].includes(x.semester)&&typeof x.is_active==='boolean'
function counts(s,keys){if(!s||keys.some(k=>!count(s[k])))fail();return s}
function sum(s,keys){return keys.reduce((n,k)=>n+s[k],0)}
export function contextFrom(r){const d=r?.data;if(!d||d.role!=='TU'||!date(d.date)||!['active','none','conflict'].includes(d.active_state)||!Array.isArray(d.recent)||d.recent.length>5||d.recent.some(x=>!period(x))||new Set(d.recent.map(x=>x.id)).size!==d.recent.length||(d.active_state==='active'?(!period(d.period)||!d.period.is_active):d.period!==null))fail();counts(d.master,['teachers','students','subjects']);return d}
export function distributionFrom(r,c){const d=r?.data;if(!d||d.academic_year_id!==c.period.id||d.date!==c.date||d.semester!==c.period.semester||!Array.isArray(d.classes)||d.classes.length>5000||d.classes.some(x=>!id(x.id)||typeof x.name!=='string'||![10,11,12].includes(x.level)||!count(x.students))||new Set(d.classes.map(x=>x.id)).size!==d.classes.length)fail();counts(d,['unique_students','memberships','invalid_intervals','duplicate_effective','cross_class']);if(d.memberships!==d.classes.reduce((n,x)=>n+x.students,0)||d.unique_students>d.memberships||d.cross_class>d.duplicate_effective||d.duplicate_effective>d.unique_students||d.memberships-d.unique_students<d.cross_class||(d.cross_class===0&&d.memberships!==d.unique_students))fail();return d}
export function accountsFrom(r){const s=counts(r?.data,['total','ready','pending','inactive','unknown']);if(s.total!==sum(s,['ready','pending','inactive','unknown']))fail();return s}
export function waliFrom(r,c){const d=r?.data;if(!d||d.period_id!==c.period.id||d.as_of!==c.date||d.active_state!=='active')fail();const s=counts(d.summary,['total','effective','scheduled','unassigned','conflict']);if(s.total!==sum(s,['effective','scheduled','unassigned','conflict']))fail();return {summary:s}}
export function attendanceFrom(r,c,p){const d=monitoringFrom(r,p,c.period,false);if(!d.meta.active||d.meta.date!==c.date)fail();const s=d.summary;const keys=p.view==='daily'?['Hadir','Izin','Sakit','Alpa','Belum ada data']:['Lengkap','Sebagian','Belum tercatat','Belum selesai','Tanpa siswa'];const total=p.view==='daily'?'Total siswa per rombel':'Pola jadwal';counts(s,[total,...keys]);if(s[total]!==sum(s,keys))fail();return d}
export function gradeFrom(r,c,p){const d=monitoringFrom(r,p,c.period,true);if(!d.meta.active||d.meta.date!==c.date)fail();counts(d.summary,['Siswa roster efektif','Jadwal','Komponen','Slot saat ini','Terisi saat ini','Belum dinilai','Slot anomali','Catatan valid','Anomali']);return d}
export function raporFrom(r,c,p){const d=parseWorkspace(r,p);if(!d.meta.active||d.meta.date!==c.date||d.meta.semester!==c.period.semester||d.warnings.some(w=>typeof w!=='string'))fail();return d}
export function ratio(n,d){return count(n)&&count(d)&&d>0&&n<=d?n/d*100:null}
export function levelTotals(rows){return [10,11,12].map(level=>({level,students:rows.filter(r=>r.level===level).reduce((n,r)=>n+r.students,0)}))}

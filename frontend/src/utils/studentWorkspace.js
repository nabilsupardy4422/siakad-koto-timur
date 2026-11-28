export function periodCatalog(response) {
  const rows = response?.data ?? response;
  if (!Array.isArray(rows)) throw new Error('Katalog periode tidak valid.');
  const seen = new Set();
  const years = rows.map(y => {
    if (!Number.isSafeInteger(Number(y.id)) || Number(y.id) <= 0 || seen.has(String(y.id)) || ![true,false,0,1,'0','1'].includes(y.is_active)) throw new Error('Katalog periode tidak valid.');
    seen.add(String(y.id)); return {...y, is_active: [true,1,'1'].includes(y.is_active)};
  });
  const active = years.filter(y => y.is_active);
  return {years, activeId: active.length === 1 ? String(active[0].id) : '', conflict: active.length > 1};
}
export function todayFocus(rows, day, now) {
  if (!now || day !== now.date) return null;
  const minute = value => {const m = /^(\d{2}):(\d{2})/.exec(value || ''); return m ? Number(m[1])*60+Number(m[2]) : NaN;};
  return rows.filter(r => minute(r.end) > now.minute && Number.isFinite(minute(r.start))).sort((a,b)=>minute(a.start)-minute(b.start))[0] || null;
}
export function progress(total, submitted) {return total > 0 ? Math.round(submitted/total*100) : null;}
export function subjectKey(row) {const id=row?.jadwal_pelajaran?.mapel?.id;return id ? `subject:${id}` : `schedule:${row.jadwal_pelajaran_id}`;}
export function assignmentPageCheck(data,meta,page,expected) {
  if(meta.current_page!==page || meta.per_page!==100 || meta.last_page!==Math.max(1,Math.ceil(meta.total/100)) || data.assignments.length!==Math.min(100,Math.max(0,meta.total-(page-1)*100))) throw new Error('Pagination tugas tidak valid.');
  const context=JSON.stringify([data.academic_year_id,data.class?.id??null,meta.total,meta.last_page]);
  if(expected!==null&&expected!==context)throw new Error('Konteks tugas berubah. Muat ulang.');return context;
}
export function submissionReadback(response, assignmentId) {
  if(!Array.isArray(response?.data)|| !Number.isInteger(response?.meta?.total)||response.meta.total!==response.data.length||response.data.length>1||response.data.some(x=>Number(x.assignment_id)!==Number(assignmentId)||!x.id))throw new Error('Status pengumpulan tidak dapat diverifikasi.');
  return response.data[0]??null;
}

export function strictGradeWorkspace(data){const seen=new Set();const counts={subjects:data.subjects.length,components:0,assessed:0,pending:0};for(const s of data.subjects){let assessed=0;for(const c of s.components){if(!Number.isSafeInteger(Number(c.id))||Number(c.id)<=0||seen.has(String(c.id))||c.assessed&&(c.score==null||c.score===''||!Number.isFinite(Number(c.score))||Number(c.score)<0||Number(c.score)>100)||!c.assessed&&c.score!==null)throw new Error('Catatan nilai tidak valid.');seen.add(String(c.id));if(c.assessed)assessed++;}if(s.assessed!==assessed||s.pending!==s.components.length-assessed)throw new Error('Ringkasan mapel tidak konsisten.');counts.components+=s.components.length;counts.assessed+=assessed;counts.pending+=s.components.length-assessed;}if(Object.entries(counts).some(([k,v])=>data.summary[k]!==v))throw new Error('Ringkasan nilai tidak konsisten.');return data;}
export function verifyAttendanceWorkspace(response,subject,page){const d=response?.data,m=response?.meta;const keys=['HADIR','IZIN','SAKIT','ALPA'];if(!d?.context||!Array.isArray(d.subjects)||!Array.isArray(d.history)||!m||m.current_page!==page||m.per_page!==10||!Number.isSafeInteger(m.total)||m.total<0||m.last_page!==Math.max(1,Math.ceil(m.total/10))||page>m.last_page||d.history.length!==Math.min(10,Math.max(0,m.total-(page-1)*10)))throw new Error('Kontrak presensi tidak valid.');const seen=new Set();for(const s of d.subjects){if(!Number.isInteger(s.id)||seen.has(s.id)||keys.some(k=>!Number.isInteger(s.summary?.[k])||s.summary[k]<0)||s.summary.total!==keys.reduce((n,k)=>n+s.summary[k],0))throw new Error('Ringkasan presensi tidak valid.');seen.add(s.id)}if(d.context.status!=='active'&&(d.subjects.length||d.history.length||m.total)||!subject&&(m.total||d.history.length)||subject&&!seen.has(Number(subject))||new Set(d.history.map(r=>r.id)).size!==d.history.length||d.history.some(r=>!keys.includes(r.status)))throw new Error('Konteks presensi tidak valid.');return response;}

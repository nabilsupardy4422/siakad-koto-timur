const fail=()=>{throw new Error('Respons rapor tidak sesuai konteks. Muat ulang.')}
const integer=x=>Number.isSafeInteger(x)&&x>=0
export const statuses=['Tersedia','Belum diunggah','Dicabut','File hilang']
export function parseWorkspace(response,params){
  const {data,meta}=response||{};
  if(!data||!meta||String(meta.academic_year_id)!==String(params.academic_year_id)||meta.view!==params.view||typeof meta.active!=='boolean')fail();
  for(const [key,value] of Object.entries(params))if(String(meta.filters?.[key]??'')!==String(value))fail();
  if(params.view==='catalog'){
    if(!Array.isArray(data.classes)||new Set(data.classes.map(c=>String(c.id))).size!==data.classes.length||data.classes.some(c=>String(c.tahun_akademik_id)!==String(params.academic_year_id)||!Number.isSafeInteger(Number(c.id))||Number(c.id)<1))fail();
    return data;
  }
  const s=data.summary;
  if(!s||!['Target siswa',...statuses,'Dokumen di luar target'].every(k=>integer(s[k]))||s['Target siswa']!==statuses.reduce((n,k)=>n+s[k],0)||!Array.isArray(data.rows)||!Array.isArray(data.warnings)||!integer(meta.total)||meta.page!==Number(params.page)||meta.per_page!==20||meta.last_page!==Math.max(1,Math.ceil(meta.total/20))||meta.page>meta.last_page||data.rows.length!==Math.min(20,Math.max(0,meta.total-(meta.page-1)*20)))fail();
  if(new Set(data.rows.map(r=>String(r.student_id))).size!==data.rows.length||data.rows.some(r=>!Number.isSafeInteger(r.student_id)||r.student_id<1||typeof r.student_name!=='string'||!statuses.includes(r.status)||typeof r.target!=='boolean'||(r.status==='Belum diunggah'?r.document_id!==null:(!Number.isSafeInteger(Number(r.document_id))||Number(r.document_id)<1||!integer(r.version)||r.version<1))))fail();
  return {...data,meta};
}
export function uploadForm(row,params,file,action,reason){
  if(!row?.student_id||!params.academic_year_id||!params.type||!file||!file.name.toLowerCase().endsWith('.pdf')||file.size<=0||file.size>10485760)throw new Error('Pilih PDF yang valid (maksimal 10 MiB).');
  const form=new FormData();
  if(action==='upload'){
    if(!params.class_id||row.document_id!==null)throw new Error('Pilih siswa dan rombel yang tepat.');
    form.append('siswa_id',String(row.student_id));form.append('tahun_akademik_id',String(params.academic_year_id));form.append('class_id',String(params.class_id));form.append('jenis_rapor',params.type);
  }else{if(!['revise','reissue'].includes(action)||!row.version||reason.trim().length<3)throw new Error('Versi atau alasan belum lengkap.');form.append('version',String(row.version));form.append('action',action);form.append('reason',reason.trim());}
  form.append('file',file);return form;
}

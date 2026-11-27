export const studentReportTypes=Object.freeze({students:'Identitas akademik',schedules:'Jadwal pelajaran',attendance:'Presensi pribadi',grades:'Nilai komponen', 'semester-recap':'Rekap akademik semester'});
export function personalReportParams(type,year,from='',to='') {
 if(!Object.hasOwn(studentReportTypes,type)||!/^\d+$/.test(String(year))||Number(year)<=0)throw new Error('Pilih jenis laporan dan periode.');
 const params={academic_year_id:String(year)};
 if(type==='attendance') {if(from&&to&&from>to)throw new Error('Rentang tanggal tidak valid.');if(from)params.date_from=from;if(to)params.date_to=to;}
 return params;
}
export function verifyPersonalPreview(response,params,page,hash=null){
 const d=response?.data;const fail=()=>{throw new Error('Pratinjau berubah atau tidak valid. Buat kembali.');};
 if(!d||d.metadata?.scope!=='Data akademik pribadi'||!Array.isArray(d.headings)||!d.headings.length||d.headings.some(h=>typeof h!=='string')||!Array.isArray(d.rows)||!Number.isSafeInteger(d.total)||d.total<0||d.metadata.count!==d.total||d.page!==page||d.per_page!==8||d.last_page!==Math.max(1,Math.ceil(d.total/8))||page>d.last_page||d.rows.length!==Math.min(8,Math.max(0,d.total-(page-1)*8))||!(/^[a-f0-9]{64}$/).test(d.preview_hash)||hash&&hash!==d.preview_hash||d.rows.some(r=>!r||typeof r!=='object'||Array.isArray(r)||Object.values(r).length!==d.headings.length||Object.values(r).some(v=>v!==null&&!['string','number','boolean'].includes(typeof v))))fail();
 const expected={...params,scope:'own'};const effective=d.effective_filters;
 if(!effective||Object.keys(effective).length!==Object.keys(expected).length||Object.entries(expected).some(([k,v])=>String(effective[k])!==String(v)))fail();return d;
}

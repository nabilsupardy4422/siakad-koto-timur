export const classTitle=c=>c?`${({10:'X',11:'XI',12:'XII'})[c.tingkat]||c.tingkat} — ${c.nama}`:'Pilih rombel'
export const periodTitle=y=>y?`${y.tahun_mulai}/${y.tahun_selesai} · ${y.semester==='GANJIL'?'Ganjil':'Genap'}`:'Pilih periode'
export const actionTitle={move:'Mutasi internal',continue:'Lanjut semester',promote:'Naik kelas',retain:'Tinggal kelas',graduate:'Lulus',withdraw:'Pindah keluar'}
export const terminalAction=a=>['graduate','withdraw'].includes(a)
export function transitionMode(from,to){
 if(!from||!to)return null
 if(String(from.id)===String(to.id))return 'same'
 if(from.semester==='GANJIL'&&to.semester==='GENAP'&&+from.tahun_mulai===+to.tahun_mulai&&+from.tahun_selesai===+to.tahun_selesai)return 'semester'
 if(from.semester==='GENAP'&&to.semester==='GANJIL'&&+to.tahun_mulai===+from.tahun_mulai+1&&+to.tahun_selesai===+from.tahun_selesai+1)return 'year'
 return null
}
export function allowedActions(mode,level){
 if(mode==='same')return ['move','withdraw']
 if(mode==='semester')return ['continue','withdraw']
 if(mode==='year')return +level===12?['graduate','retain','withdraw']:['promote','retain','withdraw']
 return []
}
export function destinationOptions(classes,origin,period,action){
 if(!origin||!action||terminalAction(action))return []
 return classes.filter(c=>String(c.tahun_akademik_id)===String(period)&&String(c.id)!==String(origin.id)&&+c.tingkat===+origin.tingkat+(action==='promote'?1:0))
}
export function previousDate(date){if(!/^\d{4}-\d{2}-\d{2}$/.test(date))return '';const d=new Date(date+'T00:00:00Z');if(!Number.isFinite(d.getTime()))return '';d.setUTCDate(d.getUTCDate()-1);return d.toISOString().slice(0,10)}
export const dateLabel=d=>d?String(d).slice(0,10).split('-').reverse().join('/'):'—'
export function buildTransitionItems(selected,mapping,overrides){return selected.map(r=>{const choice=overrides[r.id]??mapping[r.kelas_id]??{};return {membership_id:Number(r.id),action:choice.action||'',destination_class_id:terminalAction(choice.action)?null:Number(choice.destination_class_id)||null}})}

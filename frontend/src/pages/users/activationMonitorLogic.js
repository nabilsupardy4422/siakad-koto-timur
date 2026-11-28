export const codeLabels = Object.freeze({not_issued:'Belum diterbitkan / dibatalkan',valid:'Kode masih berlaku',expired:'Kode kedaluwarsa',locked:'Batas percobaan tercapai',inconsistent:'Perlu pemeriksaan',not_applicable:'Tidak diperlukan'})
export const monitorKeys = ['not_issued','valid','expired','locked','inconsistent']
export function codeMonitor(response) {
  const d=response?.data?.code_monitor
  if(!d||monitorKeys.some(k=>!Number.isSafeInteger(d[k])||d[k]<0))return null
  return Object.fromEntries(monitorKeys.map(k=>[k,d[k]]))
}
export function codeLabel(state){return Object.hasOwn(codeLabels,state)?codeLabels[state]:'Status kode belum diketahui'}
export function wibDate(value){
  if(typeof value!=='string'||!value)return 'Belum tersedia'
  const date=new Date(value)
  if(!Number.isFinite(date.getTime()))return 'Belum tersedia'
  return `${new Intl.DateTimeFormat('id-ID',{dateStyle:'medium',timeStyle:'short',timeZone:'Asia/Jakarta'}).format(date)} WIB`
}
export function validateCodeDetail(response){
  const d=response?.data
  if(!d||!Object.hasOwn(codeLabels,d.state)||typeof d.has_active_code!=='boolean'||d.has_active_code!==(d.state==='valid')||typeof d.can_cancel!=='boolean'||! /^[a-f0-9]{64}$/.test(d.code_version||'')||(d.can_cancel&&!['valid','expired','locked'].includes(d.state)))throw new Error('Status kode tidak valid. Periksa ulang.')
  return d
}

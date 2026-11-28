import {useId,useState} from 'react'
import {nativeOptions} from './teacherNativeOptions.js'
import './TeacherNativeSelect.css'
export default function TeacherNativeSelect({label,value,options=[],onChange,placeholder='Pilih opsi',disabled=false,required=false,searchPlaceholder='Cari pilihan…'}) {
 const id=useId(),[query,setQuery]=useState('')
 const selected=String(value??''); const shown=nativeOptions(options,query,selected)
 const explicitEmpty=shown.some(o=>String(o.value)==='')
 return <div className="teacher-native-field"><label htmlFor={id}>{label}{required?' *':''}</label>{options.length>12&&<input type="search" aria-label={`Cari ${label}`} placeholder={searchPlaceholder} value={query} disabled={disabled} onChange={e=>setQuery(e.target.value)}/>}
 <select id={id} value={shown.some(o=>String(o.value)===selected)?selected:''} required={required} disabled={disabled} onChange={e=>{onChange(e.target.value);setQuery('')}}>{!explicitEmpty&&<option value="" disabled={required}>{placeholder}</option>}{shown.map(o=><option key={String(o.value)} value={String(o.value)}>{[o.label,o.description].filter(Boolean).join(' · ')}</option>)}</select>
 {query&&<small role="status">{shown.filter(o=>String(o.value)!=='').length} pilihan ditampilkan. Hapus pencarian untuk melihat semua.</small>}</div>
}

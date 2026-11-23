import {useId,useState} from 'react'
import './FileDropzone.css'
export default function FileDropzone({onFiles,disabled=false,maxLabel='10 MB'}) {
 const id=useId(); const [drag,setDrag]=useState(false)
 return <div className={`siakad-file-drop${drag?' is-drag':''}`} onDragOver={e=>{e.preventDefault();if(!disabled)setDrag(true)}} onDragLeave={e=>{if(!e.currentTarget.contains(e.relatedTarget))setDrag(false)}} onDrop={e=>{e.preventDefault();setDrag(false);if(!disabled)onFiles(Array.from(e.dataTransfer.files||[]))}}>
 <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" aria-hidden="true"><path d="M12 16V4m-5 5 5-5 5 5M4 15v5h16v-5" strokeLinecap="round" strokeLinejoin="round"/></svg>
 <strong>Seret file ke sini atau pilih file</strong><span>Beberapa file sekaligus · Maksimal {maxLabel} per file</span><input id={id} type="file" multiple disabled={disabled} onChange={e=>{onFiles(Array.from(e.target.files||[]));e.target.value=''}}/><label htmlFor={id}>Pilih file</label></div>
}

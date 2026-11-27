import {useEffect,useRef,useState,useCallback} from 'react'
import {prepareStudentBlob} from './studentFileLogic.js'
import './student-workspace.css'
export function usePreparedStudentFile(context){
 const [ready,setReady]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState('')
 const generation=useRef(0),current=useRef(null)
 const clear=useCallback(()=>{generation.current++;if(current.current)URL.revokeObjectURL(current.current.url);current.current=null;setReady(null);setBusy(false);setError('')},[])
 useEffect(()=>{clear();return()=>{generation.current++;if(current.current)URL.revokeObjectURL(current.current.url);current.current=null}},[context,clear])
 async function prepare(fetchBlob,name,format){clear();const id=++generation.current;setBusy(true);try{const blob=await fetchBlob();if(id!==generation.current)return;const next=await prepareStudentBlob(blob,name,format);if(id!==generation.current){URL.revokeObjectURL(next.url);return}current.current=next;setReady(next)}catch(e){if(id===generation.current)setError(e.message||'Berkas tidak tersedia.')}finally{if(id===generation.current)setBusy(false)}}
 return {ready,busy,error,prepare,clear}
}
export default function PreparedStudentFile({state,preview=false}){
 const panel=useRef(null)
 useEffect(()=>{if(state.ready)panel.current?.scrollIntoView({block:'nearest'})},[state.ready])
 if(!state.ready&&!state.busy&&!state.error)return null
 return <section ref={panel} className="student-file-ready" aria-live="polite">{state.busy&&<p>Menyiapkan berkas terlindungi…</p>}{state.error&&<p role="alert">{state.error}</p>}{state.ready&&<><strong>{state.ready.filename}</strong><p>File siap. Pilih buka atau unduh.</p>{preview&&state.ready.canOpen&&<object data={state.ready.url} type="application/pdf" aria-label="Pratinjau PDF resmi"><p>Pratinjau tidak didukung. Gunakan Buka PDF.</p></object>}<div className="student-file-actions"><a href={state.ready.url} download={state.ready.filename}>Unduh file</a>{state.ready.canOpen&&<a href={state.ready.url} target="_blank" rel="noopener noreferrer">Buka PDF</a>}{state.ready.canOpenImage&&<a href={state.ready.url} target="_blank" rel="noopener noreferrer">Buka gambar</a>}</div></>}<button type="button" onClick={state.clear}>Tutup berkas</button></section>
}

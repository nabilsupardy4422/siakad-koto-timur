import {useEffect,useId,useRef,useState} from 'react'
import {pickerPage} from '../roles/adminScheduleWorkspace.js'

// Native top layer: no layout shift or clipping by a parent modal. No blur dismissal.
export default function SchedulePicker({label,name,value,placeholder,options,onChange,error,disabled=false,allowEmpty=true}) {
  const id=useId(),trigger=useRef(null),dialog=useRef(null)
  const [open,setOpen]=useState(false),[query,setQuery]=useState(''),[page,setPage]=useState(1)
  const selected=options.find(o=>String(o.value)===String(value))
  const result=pickerPage(options,query,page)
  const visible=open&&!disabled
  useEffect(()=>{
    const node=dialog.current
    if(!visible||!node)return
    node.showModal()
    return ()=>{if(node.open)node.close();trigger.current?.focus({preventScroll:true})}
  },[visible])
  useEffect(()=>{if(disabled)setOpen(false)},[disabled])
  function dismiss(){setOpen(false);setQuery('');setPage(1)}
  function choose(next) {
    if(disabled || (next===''&&!allowEmpty) || (next!=='' && !options.some(o=>String(o.value)===String(next))))return
    onChange({target:{name,value:String(next)}});dismiss()
  }
  return <div className="sch-picker">
    <label htmlFor={`${id}-button`}>{label}</label>
    <button ref={trigger} type="button" id={`${id}-button`} className="sch-picker__trigger" disabled={disabled} aria-haspopup="dialog" aria-expanded={visible} aria-controls={`${id}-panel`} aria-invalid={Boolean(error)} onClick={()=>{setQuery('');setPage(1);setOpen(true)}}>
      <span>{selected?.label || (value?`Pilihan #${value} tidak tersedia`:placeholder)}</span><span aria-hidden="true">⌄</span>
    </button>
    <dialog ref={dialog} className="sch-picker__dialog" id={`${id}-panel`} aria-labelledby={`${id}-title`} onCancel={e=>{e.preventDefault();dismiss()}} onKeyDown={e=>{if(e.key==='Escape'){e.preventDefault();e.stopPropagation();dismiss()}}}>
      <section className="sch-picker__panel">
        <header className="sch-picker__heading"><div><small>PILIHAN FILTER / FORM</small><h3 id={`${id}-title`}>{label}</h3></div><button type="button" className="sch-picker__x" aria-label="Tutup pilihan" onClick={dismiss}>×</button></header>
        <label htmlFor={`${id}-search`}>Cari {label.toLowerCase()}</label>
        <input id={`${id}-search`} type="search" value={query} placeholder="Ketik nama atau kode…" autoComplete="off" onChange={e=>{setQuery(e.target.value);setPage(1)}} onKeyDown={e=>{if(e.key==='Enter')e.preventDefault()}}/>
        <small role="status">{result.total} pilihan · {result.page}/{result.last}</small>
        <div className="sch-picker__choices">
          {allowEmpty&&<button type="button" className="sch-picker__option" onClick={()=>choose('')} aria-pressed={!value}>{placeholder}</button>}
          {result.rows.map(o=><button type="button" key={o.value} className="sch-picker__option" aria-pressed={String(o.value)===String(value)} onClick={()=>choose(o.value)}><span>{o.label}{o.description&&<small>{o.description}</small>}</span><span aria-hidden="true">{String(o.value)===String(value)?'✓':'›'}</span></button>)}
          {!result.rows.length&&<p>Tidak ada pilihan yang cocok.</p>}
        </div>
        {result.last>1&&<div className="sch-picker__pages"><button type="button" disabled={result.page===1} onClick={()=>setPage(result.page-1)}>Sebelumnya</button><button type="button" disabled={result.page===result.last} onClick={()=>setPage(result.page+1)}>Berikutnya</button></div>}
        <button type="button" className="sch-picker__close" onClick={dismiss}>Selesai</button>
      </section>
    </dialog>
    {error&&<small role="alert" className="sch-picker__error">{error}</small>}
  </div>
}

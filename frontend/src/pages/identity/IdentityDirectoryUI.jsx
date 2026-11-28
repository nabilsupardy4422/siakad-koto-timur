import { useEffect, useRef, useState } from 'react'
import { identityInitials, completenessView, directoryTotals } from './identityDirectoryPresentation.js'
import './IdentityDirectory.css'
import HorizontalScrollControls from '../../components/ui/HorizontalScrollControls.jsx'

export function DirectoryIdentity({name, username, email, showAccount=false}) {
  return <div className="identity-person">
    <span className="identity-avatar" aria-hidden="true">{identityInitials(name)}</span>
    <div className="identity-person-text"><strong>{name || 'Nama belum tersedia'}</strong>
      {showAccount && <><span>{username || 'Username belum tersedia'}</span>{email && <small>{email}</small>}</>}
    </div>
  </div>
}

export function DirectoryCompleteness({value}) {
  const result = completenessView(value)
  if (!result) return <span className="identity-completeness-unknown">Belum diketahui</span>
  return <div className={`identity-completeness ${result.complete ? 'is-complete' : ''}`}>
    <span>{result.filled}/{result.total} terisi</span>
    <div className="identity-meter" role="progressbar" aria-label="Kelengkapan biodata" aria-valuemin={0} aria-valuemax={result.total} aria-valuenow={result.filled}><span style={{width:`${result.percent}%`}}/></div>
  </div>
}

export function ImportProgress({preview, busy, importing=false}) {
  const step=importing?2:preview?1:0
  return <ol className="identity-import-progress" aria-label="Tahapan import" aria-busy={busy}>
    {['Unggah file','Validasi data','Simpan hasil'].map((label,i)=><li key={label} className={`${i<step?'is-done':''} ${i===step?'is-current':''}`} aria-current={i===step?'step':undefined}><span aria-hidden="true">{i<step?'✓':i+1}</span><strong>{label}</strong></li>)}
  </ol>
}

export function DirectoryOverview({api, student=false, revision}) {
  const [state,setState]=useState({loading:true,counts:null})
  useEffect(()=>{
    let live=true
    setState({loading:true,counts:null})
    const base={page_size:1,...(student?{directory_context:1}:{})}
    Promise.all([api.getAll(base),api.getAll({...base,account_state:'ready'}),api.getAll({...base,completeness:'incomplete'})])
      .then(responses=>{if(live)setState({loading:false,counts:directoryTotals(responses)})})
      .catch(()=>{if(live)setState({loading:false,counts:null})})
    return ()=>{live=false}
  },[api,student,revision])
  return <section className="identity-overview" aria-label="Ringkasan seluruh data master" aria-busy={state.loading}>
    <div className="identity-overview-caption"><strong>Ringkasan direktori</strong><span>Seluruh data master · di luar filter tabel</span></div>
    <div className="identity-overview-grid">{[['total',student?'Total siswa':'Total guru','Profil akademik'],['ready','Akun siap digunakan','Aktif dan sudah aktivasi'],['incomplete','Biodata belum lengkap','Perlu dilengkapi']].map(([key,label,note])=><article key={key} className={`identity-overview-item identity-overview-${key}`}><div><h3>{label}</h3><strong>{state.loading?'…':state.counts?new Intl.NumberFormat('id-ID').format(state.counts[key]):'—'}</strong><p>{!state.loading&&!state.counts?'Data tidak tersedia':note}</p></div><span className="identity-overview-icon" aria-hidden="true">{key==='total'?<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><circle cx="9" cy="8" r="3"/><path d="M3 19v-2a5 5 0 0 1 5-5h2a5 5 0 0 1 5 5v2M16 5a3 3 0 0 1 0 6m2 2a5 5 0 0 1 3 4v2"/></svg>:key==='ready'?<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><path d="M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6zM8 12l3 3 5-6"/></svg>:<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7"><rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 9h6M9 13h6M9 17h4"/></svg>}</span></article>)}</div>
  </section>
}

export function ImportPreviewScroll({className, label, children}) {
  const scrollRef = useRef(null)
  return <div className="identity-import-scroll-shell">
    <HorizontalScrollControls scrollRef={scrollRef} label={label}/>
    <div ref={scrollRef} className={className} tabIndex={0} role="region" aria-label={label}>{children}</div>
  </div>
}

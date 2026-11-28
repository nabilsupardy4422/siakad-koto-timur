import {useEffect,useId,useRef} from 'react'
import './WorkspaceModal.css'
import {containDialogTab} from './dialogFocus.js'
export default function WorkspaceModal({title,description,children,footer,onClose,busy=false,compact=false,className="",eyebrow=""}){
 const ref=useRef(null);const id=useId()
 useEffect(()=>{const el=ref.current;const prior=document.activeElement;if(!el.open)el.showModal();return()=>{if(el.open)el.close();if(prior?.isConnected)prior.focus({preventScroll:true})}},[])
 return <dialog ref={ref} className={`siakad-workspace-modal ${className}${compact?' is-compact':''}`} aria-labelledby={`${id}-title`} aria-describedby={description?`${id}-desc`:undefined} aria-busy={busy || undefined} onKeyDown={containDialogTab} onCancel={e=>{if(e.target!==e.currentTarget)return;e.preventDefault();e.stopPropagation();if(!busy)onClose()}}><header className="wm-head"><div>{eyebrow && <p className="wm-eyebrow">{eyebrow}</p>}<h2 id={`${id}-title`}>{title}</h2>{description&&<p id={`${id}-desc`}>{description}</p>}</div><button className="wm-close" type="button" onClick={onClose} disabled={busy} aria-label="Tutup modal">×</button></header><div className="wm-body">{children}</div><footer className="wm-footer">{footer}</footer></dialog>
}

import WorkspaceModal from './WorkspaceModal'
import './ConfirmDialog.css'
export default function ConfirmDialog({title,description,children,onClose,onConfirm,busy=false,danger=false,confirmLabel='Lanjutkan',error=''}){
  return <WorkspaceModal compact className="siakad-confirm-v2" eyebrow={danger?'KONFIRMASI PENGHAPUSAN':'KONFIRMASI PERUBAHAN'} title={title} description={description} busy={busy} onClose={onClose} footer={<><button type="button" onClick={onClose} disabled={busy}>Batal</button><button type="button" className={danger?'danger':'primary'} disabled={busy} onClick={onConfirm}>{busy?'Memproses…':confirmLabel}</button></>}><div className="scv-content">{children}{error&&<p role="alert" className="scv-error">{error}</p>}</div></WorkspaceModal>
}

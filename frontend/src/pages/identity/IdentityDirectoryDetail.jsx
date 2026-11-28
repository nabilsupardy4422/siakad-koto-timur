import { formatIdentityDate } from '../../utils/identityDate.js'
import Button from '../../components/ui/Button'
import WorkspaceModal from '../../components/ui/WorkspaceModal'
import Badge from '../../components/ui/Badge'
import { DirectoryIdentity, DirectoryCompleteness } from './IdentityDirectoryUI.jsx'
import './IdentityDirectory.css'
import {placementLabel} from './identityDirectoryLogic.js'

function FieldGroup({title, fields}) {
  const visible=fields.filter(([,value])=>value!==undefined)
  if (!visible.length) return null
  return <section className="identity-profile-section"><h3>{title}</h3><dl className="identity-directory-detail">{visible.map(([label,value])=><div key={label}><dt>{label}</dt><dd>{value===null||value===''?'Belum diisi':value}</dd></div>)}</dl></section>
}

export default function IdentityDirectoryDetail({profile, student, onClose, onEdit, onAccount, onDelete}) {
  const user=profile.user
  const status=user?.account_status==='inactive'?'Nonaktif':user?.account_status==='active'?(user.requires_activation===true?'Menunggu aktivasi':user.requires_activation===false?'Siap digunakan':'Status belum diketahui'):null
  const statusVariant=status==='Siap digunakan'?'success':status==='Menunggu aktivasi'?'warning':'neutral'
  const fields=[['Nama lengkap',profile.nama_lengkap],[student?'NISN':'NIP',student?profile.nisn:profile.nip],...(student?[['NIPD',profile.nipd],['Tempat lahir',profile.tempat_lahir],['Tanggal lahir',formatIdentityDate(profile.tanggal_lahir)]]:[]),['Jenis kelamin',profile.jenis_kelamin==='L'?'Laki-laki':profile.jenis_kelamin==='P'?'Perempuan':profile.jenis_kelamin]]
  return <WorkspaceModal className="identity-detail-workspace" title={student?'Detail Siswa':'Detail Guru'} eyebrow={student?'DATA SISWA':'DATA GURU'} description="Identitas resmi dan akun terhubung." onClose={onClose} footer={<><Button variant="secondary" onClick={onClose}>Tutup</Button>{onAccount && <Button variant="secondary" onClick={onAccount}>Kelola Akun</Button>}{onEdit && <Button onClick={onEdit}>Edit Identitas</Button>}</>}>
    <div className="identity-profile-head"><DirectoryIdentity name={profile.nama_lengkap} username={user?.username} showAccount={user?.username!==undefined}/><div className="identity-profile-head-meta">{status && <Badge variant={statusVariant} dot>{status}</Badge>}{profile.completeness && <DirectoryCompleteness value={profile.completeness}/>}</div></div>
    <FieldGroup title="Identitas akademik" fields={fields}/>
    <FieldGroup title="Informasi kontak" fields={[["Nomor HP",profile.no_telepon],["Alamat",profile.alamat]]}/>
    <FieldGroup title="Akun pengguna" fields={[["Username",user?.username],["Email",user?.email]]}/>
    {student && profile.placement && <section className="identity-directory-history identity-profile-section"><h3>Penempatan pada periode terpilih</h3><Badge variant={profile.placement.status==='conflict'?'danger':'neutral'}>{placementLabel(profile.placement)}</Badge>{profile.placement.memberships?.map(m=><div key={m.id}><strong>{m.class_name || 'Kelas tidak tersedia'}</strong><span>{formatIdentityDate(m.start) || 'Belum tercatat'} — {formatIdentityDate(m.end) || 'Belum berakhir'}</span></div>)}</section>}
    {onDelete && <section className="identity-danger-zone"><div><strong>Hapus profil akademik</strong><p>Hanya profil tanpa histori akademik yang dapat dihapus. Akun terkait akan dinonaktifkan.</p></div><Button variant="danger" onClick={onDelete}>Hapus profil</Button></section>}
  </WorkspaceModal>
}

export default function AttendanceActionBar({ count, dirty, allRecorded, saving, disabled, resetDisabled = disabled, onReset, onSave }) {
  const status = saving ? 'Menyimpan presensi…' : dirty ? 'Ada perubahan belum disimpan' : allRecorded ? 'Presensi sudah tercatat' : 'Presensi belum lengkap tersimpan'
  return <div className="action-footer">
    <div className="save-message" role="status"><span className={`draft-dot ${!dirty && allRecorded && !saving ? 'is-saved' : ''}`} aria-hidden="true"/><div><strong>{status}</strong><small>{count} siswa · {dirty || !allRecorded ? 'Periksa status sebelum menyimpan.' : 'Status mengikuti data terakhir dari server.'}</small></div></div>
    <div className="footer-buttons"><button type="button" className="secondary-btn" disabled={resetDisabled || !dirty} onClick={onReset}>Reset perubahan</button><button type="button" className="primary-btn" disabled={disabled} onClick={onSave}>{saving ? 'Menyimpan…' : '✓ Simpan presensi kelas'}</button></div>
  </div>
}

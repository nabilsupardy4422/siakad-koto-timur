export const STATUS_OPTIONS = [
  { value: 'HADIR', label: 'Hadir', summary: 'summary-present' },
  { value: 'IZIN', label: 'Izin', summary: 'summary-excused' },
  { value: 'SAKIT', label: 'Sakit', summary: 'summary-sick' },
  { value: 'ALPA', label: 'Alpa', summary: 'summary-absent' },
]
export default function AttendanceStatusSelector({ row, onChange, disabled }) {
  return <fieldset className="status-options" disabled={disabled}>
    <legend className="visually-hidden">Status kehadiran {row.nama_lengkap}</legend>
    {STATUS_OPTIONS.map(option => <label key={option.value} className={option.value}>
      <input type="radio" name={`attendance-status-${row.siswa_id}`} value={option.value} checked={row.status === option.value} onChange={() => onChange(option.value)} />
      <span>{option.label}</span>
    </label>)}
  </fieldset>
}

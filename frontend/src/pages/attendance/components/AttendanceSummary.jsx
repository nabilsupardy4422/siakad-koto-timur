import { STATUS_OPTIONS } from './AttendanceStatusSelector'
export default function AttendanceSummary({ rows }) {
  return <div className="attendance-summary" aria-label="Ringkasan isian presensi" aria-live="polite">
    <div><span>Total siswa</span><strong>{rows.length}</strong></div>
    {STATUS_OPTIONS.map(option => <div key={option.value} className={option.summary}><span><i aria-hidden="true"/>{option.label}</span><strong>{rows.filter(row => row.status === option.value).length}</strong></div>)}
  </div>
}

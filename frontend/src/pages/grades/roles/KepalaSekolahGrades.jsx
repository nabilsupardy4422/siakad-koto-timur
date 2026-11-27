import TuAcademicMonitoring from '../../tu-monitoring/TuAcademicMonitoring.jsx'
// Shared read-only presentation, separate principal API authorization. No fanout per schedule.
export default function KepalaSekolahGrades(){return <TuAcademicMonitoring grade principal/>}

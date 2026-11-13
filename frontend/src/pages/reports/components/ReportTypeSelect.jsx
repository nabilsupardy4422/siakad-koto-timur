const REPORT_TYPES = [
  { value: "students", label: "Data Siswa" },
  { value: "teachers", label: "Data Guru" },
  { value: "classes", label: "Data Kelas" },
  { value: "schedules", label: "Jadwal" },
  { value: "attendance", label: "Absensi" },
  { value: "grades", label: "Nilai" },
  { value: "semester-recap", label: "Rekap Semester (Gabungan)" },
];

export default function ReportTypeSelect({ value, onChange }) {
  return (
    <section className="reports-section">
      <label htmlFor="report-type">Jenis Laporan</label>

      <select
        id="report-type"
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        {REPORT_TYPES.map((report) => (
          <option key={report.value} value={report.value}>
            {report.label}
          </option>
        ))}
      </select>
    </section>
  );
}

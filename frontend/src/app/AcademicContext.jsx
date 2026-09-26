export default function AcademicContext({
    academicYear = '',
    semester = '',
    academicYearOptions = [],
    semesterOptions = [],
    onAcademicYearChange,
    onSemesterChange,
    disabled = false,
  }) {
    return (
      <section
        className="academic-context"
        aria-label="Konteks akademik"
      >
        <div className="academic-context__heading">
          <span>Konteks Akademik</span>
          <strong>Periode Data</strong>
        </div>

        <div className="academic-context__fields">
          <label className="academic-context__field">
            <span>Tahun Akademik</span>

            <select
              value={academicYear}
              onChange={(event) =>
                onAcademicYearChange?.(event.target.value)
              }
              disabled={disabled}
            >
              <option value="">Pilih tahun akademik</option>

              {academicYearOptions.map((option) => (
                <option
                  key={option.value}
                  value={option.value}
                >
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="academic-context__field">
            <span>Semester</span>

            <select
              value={semester}
              onChange={(event) =>
                onSemesterChange?.(event.target.value)
              }
              disabled={disabled}
            >
              <option value="">Pilih semester</option>

              {semesterOptions.map((option) => (
                <option
                  key={option.value}
                  value={option.value}
                >
                  {option.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </section>
    )
  }
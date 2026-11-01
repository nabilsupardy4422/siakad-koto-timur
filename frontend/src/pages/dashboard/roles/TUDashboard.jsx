import './TUDashboard.css'

function getDisplayName(user) {
  return user?.name || user?.username || 'Pengguna'
}

function getRoleName(user, dashboard) {
  return (
    dashboard?.role_name ||
    dashboard?.role_label ||
    user?.role?.label ||
    user?.role?.name ||
    'Tata Usaha'
  )
}

function formatAcademicYear(academicYear) {
  if (!academicYear) {
    return null
  }

  if (
    academicYear.tahun_mulai != null &&
    academicYear.tahun_selesai != null
  ) {
    return `${academicYear.tahun_mulai}/${academicYear.tahun_selesai}`
  }

  if (academicYear.tahun_ajaran) {
    return academicYear.tahun_ajaran
  }

  if (academicYear.name) {
    return academicYear.name
  }

  return null
}

function formatSemester(academicYear) {
  if (!academicYear?.semester) {
    return null
  }

  const semester = String(academicYear.semester).toLowerCase()

  if (semester === '1' || semester === 'ganjil') {
    return 'Ganjil'
  }

  if (semester === '2' || semester === 'genap') {
    return 'Genap'
  }

  return academicYear.semester
}

function getSemesterOrder(semester) {
  const normalized = String(semester ?? '').toLowerCase()

  if (normalized === 'genap' || normalized === '2') {
    return 2
  }

  if (normalized === 'ganjil' || normalized === '1') {
    return 1
  }

  return 0
}

function sortAcademicYears(academicYears) {
  return [...academicYears].sort((a, b) => {
    const endYearDifference =
      Number(b?.tahun_selesai ?? 0) -
      Number(a?.tahun_selesai ?? 0)

    if (endYearDifference !== 0) {
      return endYearDifference
    }

    const startYearDifference =
      Number(b?.tahun_mulai ?? 0) -
      Number(a?.tahun_mulai ?? 0)

    if (startYearDifference !== 0) {
      return startYearDifference
    }

    const semesterDifference =
      getSemesterOrder(b?.semester) -
      getSemesterOrder(a?.semester)

    if (semesterDifference !== 0) {
      return semesterDifference
    }

    return Number(b?.id ?? 0) - Number(a?.id ?? 0)
  })
}

function getStudentClassName(item) {
  return (
    item?.class_name ||
    item?.kelas?.nama ||
    item?.nama_kelas ||
    item?.nama ||
    'Kelas'
  )
}

function getStudentClassCount(item) {
  return (
    item?.student_count ??
    item?.jumlah_siswa ??
    item?.total_siswa ??
    item?.siswa_count ??
    item?.count ??
    0
  )
}

function ActivityIcon({ type }) {
  const commonProps = {
    width: 21,
    height: 21,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.7,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
  }

  if (type === 'teacher') {
    return (
      <svg {...commonProps}>
        <circle cx="12" cy="8" r="3.5" />
        <path d="M5 20c.8-3.2 3.1-5 7-5s6.2 1.8 7 5" />
      </svg>
    )
  }

  if (type === 'student') {
    return (
      <svg {...commonProps}>
        <circle cx="9" cy="8" r="3" />
        <path d="M3.5 20c.6-3 2.4-4.5 5.5-4.5s4.9 1.5 5.5 4.5" />
        <path d="M15 5.5a3 3 0 0 1 0 5.8" />
        <path d="M17 15.8c2.2.5 3.5 1.8 4 4.2" />
      </svg>
    )
  }

  if (type === 'class') {
    return (
      <svg {...commonProps}>
        <rect x="4" y="4" width="16" height="16" rx="2" />
        <path d="M8 8h8M8 12h8M8 16h5" />
      </svg>
    )
  }

  return (
    <svg {...commonProps}>
      <path d="M4 19V5M4 19h17" />
      <path d="m7 15 4-4 3 2 5-7" />
    </svg>
  )
}

function SummaryCard({ type, label, value, description }) {
  return (
    <article className="dashboard-activity-card">
      <div className="dashboard-activity-card__top">
        <div className="dashboard-activity-card__icon">
          <ActivityIcon type={type} />
        </div>
      </div>

      <div className="dashboard-activity-card__content">
        <span className="dashboard-activity-card__label">
          {label}
        </span>

        <h3>{value}</h3>

        <p>{description}</p>
      </div>
    </article>
  )
}

export default function TUDashboard({ user, dashboard }) {
  const displayName = getDisplayName(user)
  const roleName = getRoleName(user, dashboard)

  const summary = dashboard?.summary || {}
  const charts = dashboard?.charts || {}
  const recent = dashboard?.recent || {}

  const activeAcademicYear =
    summary?.active_academic_year || null

  const academicYearLabel =
    formatAcademicYear(activeAcademicYear)

  const semesterLabel =
    formatSemester(activeAcademicYear)

  const studentsByClass = Array.isArray(charts?.students_by_class)
    ? charts.students_by_class
    : []

  const recentAcademicYears = Array.isArray(recent?.academic_years)
    ? sortAcademicYears(recent.academic_years)
    : []

  return (
    <section className="dashboard-page">
      <header className="dashboard-hero">
        <div className="dashboard-hero__main">
          <div className="dashboard-hero__eyebrow">
            <span />
            Dashboard
          </div>

          <h1>
            Selamat datang,
            <br />
            <strong>{displayName}</strong>
          </h1>

          <p>
            Kelola informasi dan aktivitas akademik
            sekolah dari satu ruang kerja.
          </p>
        </div>

        <div className="dashboard-hero__role">
          <span>AKSES SAAT INI</span>
          <strong>{roleName}</strong>
        </div>
      </header>

      <section className="dashboard-period">
        <div className="dashboard-period__heading">
          <span>PERIODE AKADEMIK</span>
          <h2>Konteks data</h2>
        </div>

        <div className="dashboard-period__values">
          <div className="dashboard-period__value">
            <span>Tahun Akademik</span>
            <strong>{academicYearLabel || 'Belum tersedia'}</strong>
          </div>

          <div className="dashboard-period__separator" />

          <div className="dashboard-period__value">
            <span>Semester</span>
            <strong>{semesterLabel || 'Belum dipilih'}</strong>
          </div>
        </div>

        <div className="dashboard-period__state">
          <span />
          {activeAcademicYear
            ? 'Periode akademik aktif'
            : 'Periode belum ditentukan'}
        </div>
      </section>

      <section className="dashboard-section">
        <div className="dashboard-section__header">
          <div>
            <span className="dashboard-section__eyebrow">
              RINGKASAN
            </span>
            <h2>Data akademik</h2>
          </div>

          <p>
            Ringkasan data akademik berdasarkan akses {roleName}.
          </p>
        </div>

        <div className="dashboard-activity-grid">
          <SummaryCard
            type="teacher"
            label="GURU"
            value={summary?.total_guru ?? 0}
            description="Total guru yang tercatat."
          />

          <SummaryCard
            type="student"
            label="SISWA"
            value={summary?.total_siswa ?? 0}
            description="Total siswa yang tercatat."
          />

          <SummaryCard
            type="class"
            label="KELAS"
            value={summary?.total_kelas ?? 0}
            description="Total kelas pada konteks akademik."
          />

          <SummaryCard
            type="subject"
            label="MATA PELAJARAN"
            value={summary?.total_mata_pelajaran ?? 0}
            description="Total mata pelajaran yang tersedia."
          />
        </div>
      </section>

      <section className="dashboard-section">
        <div className="dashboard-section__header">
          <div>
            <span className="dashboard-section__eyebrow">
              DISTRIBUSI SISWA
            </span>
            <h2>Siswa per kelas</h2>
          </div>

          <p>
            Jumlah siswa aktif berdasarkan kelas pada
            konteks akademik yang tersedia.
          </p>
        </div>

        <div className="dashboard-list">
          {studentsByClass.length === 0 ? (
            <div className="dashboard-empty">
              <div className="dashboard-empty__mark">
                <span />
              </div>

              <div>
                <strong>Belum ada data kelas</strong>
                <p>
                  Distribusi siswa per kelas akan muncul
                  setelah data kelas dan siswa tersedia.
                </p>
              </div>
            </div>
          ) : (
            studentsByClass.map((item, index) => (
              <div
                className="dashboard-list__item"
                key={
                  item?.class_id ??
                  item?.kelas?.id ??
                  item?.kelas_id ??
                  item?.id ??
                  `${getStudentClassName(item)}-${index}`
                }
              >
                <div>
                  <strong>{getStudentClassName(item)}</strong>
                  <span>
                    {item?.tingkat != null
                      ? `Tingkat ${item.tingkat} · `
                      : ''}
                    Siswa aktif
                  </span>
                </div>

                <strong>{getStudentClassCount(item)}</strong>
              </div>
            ))
          )}
        </div>
      </section>

      <section className="dashboard-section dashboard-section--recent">
        <div className="dashboard-section__header">
          <div>
            <span className="dashboard-section__eyebrow">
              RIWAYAT AKADEMIK
            </span>
            <h2>Tahun akademik terbaru</h2>
          </div>

          <p>
            Lima tahun akademik terbaru yang tersedia
            pada sistem.
          </p>
        </div>

        <div className="dashboard-list">
          {recentAcademicYears.length === 0 ? (
            <div className="dashboard-empty">
              <div className="dashboard-empty__mark">
                <span />
              </div>

              <div>
                <strong>Belum ada tahun akademik</strong>
                <p>
                  Data tahun akademik akan muncul
                  setelah periode akademik tersedia.
                </p>
              </div>
            </div>
          ) : (
            recentAcademicYears.map((academicYear, index) => (
              <div
                className="dashboard-list__item"
                key={
                  academicYear?.id ??
                  `${formatAcademicYear(academicYear)}-${index}`
                }
              >
                <div>
                  <strong>
                    {formatAcademicYear(academicYear) ||
                      'Tahun akademik'}
                  </strong>

                  <span>
                    Semester {formatSemester(academicYear) || '-'}
                  </span>
                </div>

                <span>
                  {academicYear?.is_active ? 'Aktif' : 'Tidak aktif'}
                </span>
              </div>
            ))
          )}
        </div>
      </section>
    </section>
  )
}

import { useDashboard } from '../hooks/useDashboard'
import './SiswaDashboard.css'

function SummaryCard({ label, value, icon }) {
  return (
    <article className="student-summary-card">
      <div className="student-summary-card__top">
        <span className="student-summary-card__icon" aria-hidden="true">
          {icon}
        </span>

        <span className="student-summary-card__label">
          {label}
        </span>
      </div>

      <strong className="student-summary-card__value">
        {value ?? 0}
      </strong>

      <span className="student-summary-card__caption">
        Data akademik Anda
      </span>
    </article>
  )
}

export default function SiswaDashboard() {
  const { dashboard, loading, error } = useDashboard()

  if (loading) {
    return (
      <section className="dashboard-page student-dashboard">
        <div className="student-dashboard__state">
          <span className="student-dashboard__spinner" />
          <p>Memuat dashboard siswa...</p>
        </div>
      </section>
    )
  }

  if (error) {
    return (
      <section className="dashboard-page student-dashboard">
        <div className="student-dashboard__error" role="alert">
          <strong>Dashboard tidak dapat dimuat</strong>
          <p>{error.message || 'Terjadi kesalahan saat mengambil data.'}</p>
        </div>
      </section>
    )
  }

  const summary = dashboard?.summary ?? {}
  const studentClass = dashboard?.class
  const academicYear = studentClass?.academic_year

  const cards = [
    {
      label: 'Presensi',
      value: summary.attendance_count,
      icon: '✓',
    },
    {
      label: 'Nilai',
      value: summary.grade_count,
      icon: 'A',
    },
    {
      label: 'Bahan Ajar',
      value: summary.material_count,
      icon: 'B',
    },
    {
      label: 'Tugas',
      value: summary.assignment_count,
      icon: 'T',
    },
  ]

  return (
    <section className="dashboard-page student-dashboard">
      <header className="student-dashboard__hero">
        <div className="student-dashboard__hero-content">
          <div className="student-dashboard__eyebrow">
            <span className="student-dashboard__eyebrow-dot" />
            DASHBOARD SISWA
          </div>

          <h1>
            Selamat datang,
            <strong>{dashboard?.user?.name || 'Siswa'}</strong>
          </h1>

          <p>
            Pantau kegiatan belajar, presensi, nilai, bahan ajar,
            dan tugas Anda melalui satu halaman.
          </p>
        </div>

        <div className="student-dashboard__hero-decoration" aria-hidden="true">
          <span />
          <span />
          <span />
        </div>
      </header>

      <section className="student-dashboard__section">
        <div className="student-dashboard__section-heading">
          <div>
            <span className="student-dashboard__section-eyebrow">
              INFORMASI AKADEMIK
            </span>
            <h2>Ringkasan Akademik</h2>
            <p>Informasi aktivitas akademik Anda saat ini.</p>
          </div>
        </div>

        <div className="student-dashboard__summary-grid">
          {cards.map((card) => (
            <SummaryCard
              key={card.label}
              label={card.label}
              value={card.value}
              icon={card.icon}
            />
          ))}
        </div>
      </section>

      <section className="student-dashboard__class-card">
        <div className="student-dashboard__class-icon" aria-hidden="true">
          <svg
            viewBox="0 0 24 24"
            width="28"
            height="28"
            fill="none"
            stroke="currentColor"
            strokeWidth="1.7"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <path d="M3 10 12 4l9 6" />
            <path d="M5 10v9h14v-9" />
            <path d="M9 19v-5h6v5" />
          </svg>
        </div>

        <div className="student-dashboard__class-info">
          <span className="student-dashboard__section-eyebrow">
            KELAS SAYA
          </span>

          <h2>
            {studentClass?.nama || 'Kelas belum tersedia'}
          </h2>

          {studentClass ? (
            <>
              <p>
                Tingkat {studentClass.tingkat}
                {academicYear
                  ? ` · ${academicYear.tahun_mulai}/${academicYear.tahun_selesai} ${academicYear.semester}`
                  : ''}
              </p>

              {academicYear && !academicYear.is_active && (
                <span className="student-dashboard__year-status">
                  Tahun akademik tidak aktif
                </span>
              )}
            </>
          ) : (
            <p>
              Informasi kelas belum tersedia. Silakan hubungi Tata Usaha
              untuk memastikan penempatan kelas Anda.
            </p>
          )}
        </div>
      </section>
    </section>
  )
}

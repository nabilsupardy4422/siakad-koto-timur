import { useAuth } from '../../context/useAuth'

function getRoleName(role) {
  return (
    role?.name ||
    role?.label ||
    role?.code ||
    'Pengguna'
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

  if (type === 'schedule') {
    return (
      <svg {...commonProps}>
        <rect x="4" y="5" width="16" height="15" rx="2" />
        <path d="M8 3v4M16 3v4M4 10h16" />
        <path d="M8 14h2M14 14h2M8 17h2" />
      </svg>
    )
  }

  if (type === 'attendance') {
    return (
      <svg {...commonProps}>
        <rect x="5" y="3" width="14" height="18" rx="2" />
        <path d="M8 8h8M8 12h3M8 16h2" />
        <path d="m14 15 1.5 1.5L18 14" />
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

function ActivityCard({
  type,
  label,
  title,
  description,
}) {
  return (
    <article className="dashboard-activity-card">
      <div className="dashboard-activity-card__top">
        <div className="dashboard-activity-card__icon">
          <ActivityIcon type={type} />
        </div>

        <span className="dashboard-activity-card__status">
          Belum tersedia
        </span>
      </div>

      <div className="dashboard-activity-card__content">
        <span className="dashboard-activity-card__label">
          {label}
        </span>

        <h3>{title}</h3>

        <p>{description}</p>
      </div>

      <div
        className="dashboard-activity-card__arrow"
        aria-hidden="true"
      >
        →
      </div>
    </article>
  )
}

export default function DashboardPage() {
  const { user, role } = useAuth()

  const displayName =
    user?.name ||
    user?.username ||
    'Pengguna'

  const roleName = getRoleName(role)

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
            <strong>Belum dipilih</strong>
          </div>

          <div className="dashboard-period__separator" />

          <div className="dashboard-period__value">
            <span>Semester</span>
            <strong>Belum dipilih</strong>
          </div>
        </div>

        <div className="dashboard-period__state">
          <span />
          Periode belum ditentukan
        </div>
      </section>

      <section className="dashboard-section">
        <div className="dashboard-section__header">
          <div>
            <span className="dashboard-section__eyebrow">
              RUANG KERJA
            </span>

            <h2>Aktivitas akademik</h2>
          </div>

          <p>
            Informasi akan mengikuti hak akses dan
            periode akademik yang aktif.
          </p>
        </div>

        <div className="dashboard-activity-grid">
          <ActivityCard
            type="schedule"
            label="Jadwal"
            title="Jadwal akademik"
            description="Jadwal pelajaran akan ditampilkan setelah periode akademik dipilih."
          />

          <ActivityCard
            type="attendance"
            label="Presensi"
            title="Rekap kehadiran"
            description="Informasi presensi tersedia sesuai akses akun dan periode akademik."
          />

          <ActivityCard
            type="grade"
            label="Penilaian"
            title="Hasil penilaian"
            description="Data penilaian akan ditampilkan berdasarkan kewenangan pengguna."
          />
        </div>
      </section>

      <section className="dashboard-section dashboard-section--recent">
        <div className="dashboard-section__header">
          <div>
            <span className="dashboard-section__eyebrow">
              TERBARU
            </span>

            <h2>Aktivitas terbaru</h2>
          </div>
        </div>

        <div className="dashboard-empty">
          <div className="dashboard-empty__mark">
            <span />
          </div>

          <div>
            <strong>Belum ada aktivitas</strong>

            <p>
              Aktivitas akademik terbaru akan muncul
              di area ini setelah data tersedia.
            </p>
          </div>
        </div>
      </section>
    </section>
  )
}
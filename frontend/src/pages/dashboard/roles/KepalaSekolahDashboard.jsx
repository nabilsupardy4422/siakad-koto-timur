
import { useDashboard } from '../hooks/useDashboard'
import './KepalaSekolahDashboard.css'

function SummaryCard({ label, value, icon, description }) {
  return (
    <article className="principal-summary-card">
      <div className="principal-summary-card__top">
        <span
          className="principal-summary-card__icon"
          aria-hidden="true"
        >
          {icon}
        </span>

        <span className="principal-summary-card__label">
          {label}
        </span>
      </div>

      <strong className="principal-summary-card__value">
        {value ?? 0}
      </strong>

      <p className="principal-summary-card__caption">
        {description}
      </p>
    </article>
  )
}

export default function KepalaSekolahDashboard() {
  const { dashboard, loading, error } = useDashboard()

  if (loading) {
    return (
      <section className="dashboard-page principal-dashboard">
        <div className="principal-dashboard__state">
          <span
            className="principal-dashboard__spinner"
            aria-hidden="true"
          />
          <p>Memuat dashboard Kepala Sekolah...</p>
        </div>
      </section>
    )
  }

  if (error) {
    return (
      <section className="dashboard-page principal-dashboard">
        <div className="principal-dashboard__error" role="alert">
          <strong>Dashboard tidak dapat dimuat</strong>
          <p>
            {error.message ||
              'Terjadi kesalahan saat mengambil data dashboard.'}
          </p>
        </div>
      </section>
    )
  }

  const summary = dashboard?.summary ?? {}

  const cards = [
    {
      label: 'Guru',
      value: summary.total_guru,
      icon: 'G',
      description: 'Total data guru',
    },
    {
      label: 'Siswa',
      value: summary.total_siswa,
      icon: 'S',
      description: 'Total data siswa',
    },
    {
      label: 'Kelas',
      value: summary.total_kelas,
      icon: 'K',
      description: 'Total rombongan belajar',
    },
    {
      label: 'Jadwal',
      value: summary.total_jadwal,
      icon: 'J',
      description: 'Jadwal yang tercatat',
    },
  ]

  return (
    <section className="dashboard-page principal-dashboard">
      <header className="principal-dashboard__hero">
        <div className="principal-dashboard__hero-content">
          <div className="principal-dashboard__eyebrow">
            <span className="principal-dashboard__eyebrow-dot" />
            DASHBOARD KEPALA SEKOLAH
          </div>

          <h1>
            Ringkasan,
            <strong>Kepala Sekolah</strong>
          </h1>

          <p>
            Pantau ringkasan data guru, siswa, kelas, dan jadwal
            akademik sekolah melalui satu halaman.
          </p>
        </div>

        <div
          className="principal-dashboard__decoration"
          aria-hidden="true"
        >
          <span />
          <span />
          <span />
        </div>
      </header>

      <section className="principal-dashboard__section">
        <div className="principal-dashboard__section-heading">
          <div>
            <span className="principal-dashboard__section-eyebrow">
              RINGKASAN AKADEMIK
            </span>

            <h2>Data akademik sekolah</h2>

            <p className="principal-dashboard__section-description">
              Ringkasan berdasarkan data yang tersedia di sistem.
            </p>
          </div>
        </div>

        <div className="principal-dashboard__summary-grid">
          {cards.map((card) => (
            <SummaryCard
              key={card.label}
              label={card.label}
              value={card.value}
              icon={card.icon}
              description={card.description}
            />
          ))}
        </div>
      </section>
    </section>
  )
}

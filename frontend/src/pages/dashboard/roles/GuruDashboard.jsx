
import { useAuth } from '../../../context/useAuth'
import { useDashboard } from '../hooks/useDashboard'
import LoadingState from '../../../components/ui/LoadingState'
import ErrorState from '../../../components/ui/ErrorState'
import './GuruDashboard.css'

function SummaryCard({ label, value, description, marker }) {
  return (
    <article className="guru-summary-card">
      <div className="guru-summary-card__top">
        <span className="guru-summary-card__marker">{marker}</span>
        <span className="guru-summary-card__label">{label}</span>
      </div>

      <strong className="guru-summary-card__value">
        {value ?? 0}
      </strong>

      <p className="guru-summary-card__description">
        {description}
      </p>
    </article>
  )
}

export default function GuruDashboard() {
  const { user } = useAuth()
  const { dashboard, loading, error } = useDashboard()

  if (loading) {
    return <LoadingState message="Memuat dashboard Guru..." />
  }

  if (error) {
    return (
      <ErrorState
        message={error.message || 'Dashboard Guru tidak dapat dimuat.'}
      />
    )
  }

  if (!dashboard) {
    return (
      <ErrorState message="Data dashboard Guru tidak tersedia." />
    )
  }

  const summary = dashboard.summary ?? {}
  const displayName =
    user?.name ||
    user?.username ||
    dashboard?.user?.name ||
    'Guru'

  const isHomeroomTeacher = dashboard.is_wali_kelas === true
  const homeroomClass = dashboard.wali_kelas?.kelas?.nama

  return (
    <section className="dashboard-page guru-dashboard">
      <header className="guru-dashboard__hero">
        <div className="guru-dashboard__hero-content">
          <span className="guru-dashboard__eyebrow">
            <span className="guru-dashboard__eyebrow-dot" />
            DASHBOARD GURU
          </span>

          <h1>
            Selamat datang,
            <br />
            <strong>{displayName}</strong>
          </h1>

          <p>
            Pantau jadwal mengajar dan aktivitas akademik
            Anda melalui satu halaman.
          </p>
        </div>

        <div className="guru-dashboard__hero-decoration" aria-hidden="true" />
      </header>

      <section className="guru-dashboard__section">
        <div className="guru-dashboard__section-heading">
          <div>
            <span className="guru-dashboard__section-eyebrow">
              AKTIVITAS MENGAJAR
            </span>
            <h2>Ringkasan aktivitas</h2>
          </div>
          <p>Data ditampilkan berdasarkan respons sistem.</p>
        </div>

        <div className="guru-dashboard__summary-grid">
          <SummaryCard
            marker="J"
            label="Jadwal hari ini"
            value={summary.today_schedule_count}
            description="Jadwal mengajar hari ini"
          />

          <SummaryCard
            marker="M"
            label="Jadwal mengajar"
            value={summary.teaching_schedule_count}
            description="Jumlah jadwal mengajar"
          />

          <SummaryCard
            marker="B"
            label="Bahan ajar"
            value={summary.material_count}
            description="Bahan ajar yang tercatat"
          />

          <SummaryCard
            marker="T"
            label="Tugas"
            value={summary.assignment_count}
            description="Tugas yang tercatat"
          />

          <SummaryCard
            marker="N"
            label="Komponen nilai"
            value={summary.grade_component_count}
            description="Komponen penilaian"
          />

          <SummaryCard
            marker="P"
            label="Catatan presensi"
            value={summary.attendance_record_count}
            description="Catatan presensi yang tercatat"
          />
        </div>
      </section>

      <section className="guru-dashboard__section">
        <div className="guru-dashboard__section-heading">
          <div>
            <span className="guru-dashboard__section-eyebrow">
              KONTEKS AKADEMIK
            </span>
            <h2>Status Wali Kelas</h2>
          </div>
        </div>

        <div className="guru-dashboard__homeroom">
          <div
            className={`guru-dashboard__status-icon ${
              isHomeroomTeacher
                ? 'guru-dashboard__status-icon--active'
                : ''
            }`}
            aria-hidden="true"
          >
            {isHomeroomTeacher ? 'W' : '—'}
          </div>

          <div className="guru-dashboard__homeroom-content">
            <span
              className={`guru-dashboard__status-label ${
                isHomeroomTeacher
                  ? 'guru-dashboard__status-label--active'
                  : ''
              }`}
            >
              {isHomeroomTeacher
                ? 'Memiliki penugasan Wali Kelas aktif'
                : 'Tidak ada penugasan Wali Kelas aktif'}
            </span>

            <p>
              {isHomeroomTeacher
                ? homeroomClass
                  ? `Kelas yang menjadi tanggung jawab: ${homeroomClass}.`
                  : 'Data penugasan aktif tersedia, tetapi nama kelas belum tersedia.'
                : 'Anda tetap dapat menjalankan aktivitas mengajar sesuai hak akses. Riwayat penugasan yang telah berakhir tidak dihapus.'}
            </p>
          </div>
        </div>
      </section>
    </section>
  )
}

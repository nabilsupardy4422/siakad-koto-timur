import { useAuth } from '../../context/useAuth'

export default function DashboardPage() {
  const { user, role } = useAuth()

  const roleName =
    role?.name ||
    role?.label ||
    role?.code ||
    'Pengguna'

  return (
    <section className="dashboard-page">
      <div className="dashboard-page__intro">
        <p className="dashboard-page__eyebrow">
          DASHBOARD
        </p>

        <h2>
          Selamat datang, {user?.name || user?.username}.
        </h2>

        <p>
          Anda masuk sebagai {roleName}. Informasi dan menu
          yang tersedia disesuaikan dengan akses akun Anda.
        </p>
      </div>

      <div className="dashboard-page__grid">
        <article className="dashboard-summary">
          <span>Role</span>
          <strong>{roleName}</strong>
        </article>

        <article className="dashboard-summary">
          <span>Username</span>
          <strong>{user?.username || '-'}</strong>
        </article>

        <article className="dashboard-summary">
          <span>Status</span>
          <strong>Aktif</strong>
        </article>
      </div>
    </section>
  )
}
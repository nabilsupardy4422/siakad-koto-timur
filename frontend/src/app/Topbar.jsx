import Breadcrumb from './Breadcrumb'
import UserMenu from './UserMenu'

export default function Topbar({ onMenuClick }) {
  return (
    <header className="app-topbar">
      <div className="app-topbar__left">
        <button
          type="button"
          className="app-topbar__menu"
          onClick={onMenuClick}
          aria-label="Buka menu"
        >
          <span />
          <span />
          <span />
        </button>

        <Breadcrumb />

        <div className="app-topbar__context">
          <p>Sistem Informasi Akademik</p>
          <h1>Portal Sekolah</h1>
        </div>
      </div>

      <div className="app-topbar__user">
        <UserMenu />
      </div>
    </header>
  )
}
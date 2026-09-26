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

        <div className="app-topbar__heading">
          <Breadcrumb />

          <div className="app-topbar__context">
            <span>
              Sistem Informasi Akademik
            </span>

            <strong>
              Portal Sekolah
            </strong>
          </div>
        </div>
      </div>

      <div className="app-topbar__user">
        <UserMenu />
      </div>
    </header>
  )
}
import { Link } from 'react-router-dom'
import Breadcrumb from './Breadcrumb'
import UserMenu from './UserMenu'
import './Topbar.css'

export default function Topbar({ open = false, onMenuClick }) {
  return (
    <header className="app-topbar">
      <div className="app-topbar__left">
        <button
          type="button"
          className="app-topbar__menu"
          onClick={onMenuClick}
          aria-label="Buka navigasi workspace"
          aria-expanded={open}
          aria-controls="siakad-workspace-sidebar"
        >
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="M4 7h16M4 12h16M4 17h12" />
          </svg>
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
        <Link className="app-topbar__site-link" to="/" title="Kembali ke website sekolah">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
            <path d="m3.5 10 8.5-7 8.5 7V20H3.5z" />
            <path d="M9 20v-6h6v6" />
          </svg>
          <span>Website sekolah</span>
        </Link>
        <span className="app-topbar__divider" aria-hidden="true" />
        <UserMenu />
      </div>
    </header>
  )
}
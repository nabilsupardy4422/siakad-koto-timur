import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/useAuth'
import ProfilePhotoImage from '../components/profile/ProfilePhotoImage'
import './UserMenu.css'

function UserIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <circle cx="12" cy="8" r="3.2" />
      <path d="M5.5 20c.7-3.3 2.9-5 6.5-5s5.8 1.7 6.5 5" />
    </svg>
  )
}

function LogoutIcon() {
  return (
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
      <path d="M10 5H5v14h5" />
      <path d="M14 8l4 4-4 4" />
      <path d="M9 12h9" />
    </svg>
  )
}

function ChevronIcon({ open }) {
  return (
    <svg
      className={`app-user-menu__chevron ${open ? 'is-open' : ''}`}
      width="13" height="13" viewBox="0 0 24 24" fill="none"
      stroke="currentColor" strokeWidth="1.8" strokeLinecap="round"
      strokeLinejoin="round" aria-hidden="true"
    >
      <path d="m7 10 5 5 5-5" />
    </svg>
  )
}

export default function UserMenu() {
  const { user, role, logout } = useAuth()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const menuRef = useRef(null)
  const triggerRef = useRef(null)

  const name = user?.name || user?.username || 'Pengguna'
  const roleName = role?.name || role?.label || role?.code || 'Pengguna'
  const initial = name.charAt(0).toUpperCase()

  useEffect(() => {
    function handleDocumentClick(event) {
      if (menuRef.current && !menuRef.current.contains(event.target)) {
        setOpen(false)
      }
    }

    function handleEscape(event) {
      if (event.key === 'Escape') {
        setOpen(false)
        if (menuRef.current?.contains(document.activeElement)) {
          triggerRef.current?.focus()
        }
      }
    }

    document.addEventListener('mousedown', handleDocumentClick)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handleDocumentClick)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [])

  function handleProfile() {
    setOpen(false)
    navigate('/app/profil')
  }

  async function handleLogout() {
    setOpen(false)
    await logout()
  }

  return (
    <div className="app-user-menu" ref={menuRef}>
      <button
        ref={triggerRef}
        type="button"
        className="app-user-menu__trigger"
        onClick={() => setOpen((current) => !current)}
        aria-expanded={open}
        aria-haspopup="menu"
        aria-label="Menu pengguna"
      >
        <span className="app-user-menu__identity">
          <strong>{name}</strong>
          <span>{roleName}</span>
        </span>

        <span className="app-user-menu__avatar">
          <ProfilePhotoImage userId={user?.id} fallback={initial} />
        </span>
        <ChevronIcon open={open} />
      </button>

      {open && (
        <div className="app-user-menu__dropdown" role="menu" aria-label="Menu akun pengguna">
          <div className="app-user-menu__summary">
            <span className="app-user-menu__summary-avatar">
              <ProfilePhotoImage userId={user?.id} fallback={initial} />
            </span>
            <div>
              <strong>{name}</strong>
              <span>{roleName}</span>
            </div>
          </div>

          <div className="app-user-menu__divider" />

          <button type="button" className="app-user-menu__item" role="menuitem" onClick={handleProfile}>
            <UserIcon />
            <span>Profil Saya</span>
          </button>

          <button
            type="button"
            className="app-user-menu__item app-user-menu__item--danger"
            role="menuitem"
            onClick={handleLogout}
          >
            <LogoutIcon />
            <span>Keluar</span>
          </button>
        </div>
      )}
    </div>
  )
}

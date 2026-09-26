import { useEffect, useRef, useState } from 'react'
import { useAuth } from '../context/useAuth'

export default function UserMenu() {
  const { user, role, logout } = useAuth()
  const [open, setOpen] = useState(false)
  const menuRef = useRef(null)

  const name = user?.name || user?.username || 'Pengguna'
  const roleName = role?.name || role?.code || 'Pengguna'
  const initial = name.charAt(0).toUpperCase()

  useEffect(() => {
    function handleDocumentClick(event) {
      if (
        menuRef.current &&
        !menuRef.current.contains(event.target)
      ) {
        setOpen(false)
      }
    }

    document.addEventListener('mousedown', handleDocumentClick)

    return () => {
      document.removeEventListener(
        'mousedown',
        handleDocumentClick,
      )
    }
  }, [])

  async function handleLogout() {
    setOpen(false)
    await logout()
  }

  return (
    <div className="app-user-menu" ref={menuRef}>
      <button
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
          {initial}
        </span>
      </button>

      {open && (
        <div className="app-user-menu__dropdown" role="menu">
          <div className="app-user-menu__summary">
            <strong>{name}</strong>
            <span>{roleName}</span>
          </div>

          <div className="app-user-menu__divider" />

          <button
            type="button"
            className="app-user-menu__item"
            role="menuitem"
            disabled
            title="Profil akan tersedia pada modul akun"
          >
            Profil
          </button>

          <button
            type="button"
            className="app-user-menu__item app-user-menu__item--danger"
            role="menuitem"
            onClick={handleLogout}
          >
            Keluar
          </button>
        </div>
      )}
    </div>
  )
}
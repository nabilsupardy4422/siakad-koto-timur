import { NavLink } from 'react-router-dom'
import navigation from '../Config/navigation'
import { useAuth } from '../context/useAuth'

function hasPermission(item, permissions) {
  if (!item.permission) {
    return true
  }

  return permissions.includes(item.permission)
}

function hasAssignment(item, assignments) {
  if (!item.assignment) {
    return true
  }

  return assignments.some(
    (assignment) => assignment.type === item.assignment,
  )
}

export default function Sidebar({ open, onClose }) {
  const { role, permissions, assignments, user } = useAuth()

  const roleCode =
    typeof role === 'string'
      ? role
      : role?.code || role?.name || null

  const items = navigation[roleCode] || []

  const visibleItems = items.filter(
    (item) =>
      hasPermission(item, permissions) &&
      hasAssignment(item, assignments),
  )

  return (
    <>
      <div
        className={`app-sidebar-backdrop ${open ? 'is-visible' : ''}`}
        onClick={onClose}
        aria-hidden="true"
      />

      <aside className={`app-sidebar ${open ? 'is-open' : ''}`}>
        <div className="app-sidebar__header">
          <div className="app-sidebar__brand">
            <img
              src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
              alt="Logo SMA Negeri 1 V Koto Timur"
            />

            <div>
              <strong>SIAKAD</strong>
              <span>SMA Negeri 1 V Koto Timur</span>
            </div>
          </div>

          <button
            type="button"
            className="app-sidebar__close"
            onClick={onClose}
            aria-label="Tutup menu"
          >
            ×
          </button>
        </div>

        <div className="app-sidebar__profile">
          <div className="app-sidebar__avatar">
            {(user?.name || user?.username || 'U')
              .charAt(0)
              .toUpperCase()}
          </div>

          <div>
            <strong>{user?.name || user?.username || 'Pengguna'}</strong>
            <span>{role?.name || roleCode || 'Pengguna'}</span>
          </div>
        </div>

        <nav className="app-sidebar__nav" aria-label="Navigasi utama">
          {visibleItems.map((item, index) =>
            item.section ? (
              <div
                key={`${item.label}-${index}`}
                className="app-sidebar__section"
              >
                {item.label}
              </div>
            ) : (
              <NavLink
                key={item.path}
                to={item.path}
                end={item.path === '/app/dashboard'}
                className={({ isActive }) =>
                  `app-sidebar__link ${isActive ? 'is-active' : ''}`
                }
                onClick={onClose}
              >
                <span>{item.label}</span>
              </NavLink>
            ),
          )}
        </nav>
      </aside>
    </>
  )
}
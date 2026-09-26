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

function MenuIcon({ label }) {
  const commonProps = {
    width: 17,
    height: 17,
    viewBox: '0 0 24 24',
    fill: 'none',
    stroke: 'currentColor',
    strokeWidth: 1.7,
    strokeLinecap: 'round',
    strokeLinejoin: 'round',
    'aria-hidden': true,
  }

  switch (label) {
    case 'Dashboard':
      return (
        <svg {...commonProps}>
          <rect x="4" y="4" width="6" height="6" rx="1" />
          <rect x="14" y="4" width="6" height="6" rx="1" />
          <rect x="4" y="14" width="6" height="6" rx="1" />
          <rect x="14" y="14" width="6" height="6" rx="1" />
        </svg>
      )

    case 'Tahun Akademik':
      return (
        <svg {...commonProps}>
          <rect x="4" y="5" width="16" height="15" rx="2" />
          <path d="M8 3v4M16 3v4M4 10h16" />
          <path d="M8 14h2M14 14h2M8 17h2" />
        </svg>
      )

    case 'Guru':
      return (
        <svg {...commonProps}>
          <circle cx="12" cy="8" r="3" />
          <path d="M6 20c.6-3.2 2.5-5 6-5s5.4 1.8 6 5" />
        </svg>
      )

    case 'Siswa':
      return (
        <svg {...commonProps}>
          <circle cx="9" cy="8" r="3" />
          <path d="M3.5 20c.5-3.1 2.3-5 5.5-5" />
          <circle cx="17" cy="9" r="2.5" />
          <path d="M14 20c.4-2.5 1.5-4 4-4 1.5 0 2.5.5 3 1.5" />
        </svg>
      )

    case 'Mata Pelajaran':
      return (
        <svg {...commonProps}>
          <rect x="5" y="4" width="14" height="16" rx="2" />
          <path d="M8 8h8M8 12h8M8 16h5" />
        </svg>
      )

    case 'Kelas':
      return (
        <svg {...commonProps}>
          <path d="M4 5h16v14H4z" />
          <path d="M8 9h8M8 13h5" />
        </svg>
      )

    case 'Jadwal':
      return (
        <svg {...commonProps}>
          <rect x="4" y="5" width="16" height="15" rx="2" />
          <path d="M8 3v4M16 3v4M4 10h16" />
          <path d="M8 14h2M14 14h2M8 17h2" />
        </svg>
      )

    case 'Presensi':
      return (
        <svg {...commonProps}>
          <rect x="5" y="3" width="14" height="18" rx="2" />
          <path d="M8 8h8M8 12h3M8 16h2" />
          <path d="m14 15 1.5 1.5L18 14" />
        </svg>
      )

    case 'Penilaian':
      return (
        <svg {...commonProps}>
          <path d="M4 19V5M4 19h17" />
          <path d="m7 15 4-4 3 2 5-7" />
        </svg>
      )

    case 'Bahan Ajar':
      return (
        <svg {...commonProps}>
          <path d="M5 4h14v16H5z" />
          <path d="M8 8h8M8 12h8M8 16h5" />
        </svg>
      )

    case 'Tugas':
      return (
        <svg {...commonProps}>
          <path d="M6 4h12v16H6z" />
          <path d="M9 4.5V3h6v1.5" />
          <path d="M9 10h6M9 14h4" />
        </svg>
      )

    case 'Rapor':
      return (
        <svg {...commonProps}>
          <path d="M6 4h12v16H6z" />
          <path d="M9 8h6M9 12h6M9 16h3" />
        </svg>
      )

    case 'Laporan':
      return (
        <svg {...commonProps}>
          <path d="M4 19V5M4 19h17" />
          <path d="M8 16v-4M12 16V8M16 16v-6M20 16v-9" />
        </svg>
      )

    case 'Pengguna':
      return (
        <svg {...commonProps}>
          <circle cx="9" cy="8" r="3" />
          <path d="M3.5 20c.5-3.2 2.3-5 5.5-5s5 1.8 5.5 5" />
          <path d="M16 11h5M18.5 8.5v5" />
        </svg>
      )

    default:
      return (
        <svg {...commonProps}>
          <circle cx="12" cy="12" r="7" />
        </svg>
      )
  }
}

export default function Sidebar({ open, onClose }) {
  const { role, permissions, assignments } = useAuth()

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
        className={`app-sidebar-backdrop ${
          open ? 'is-visible' : ''
        }`}
        onClick={onClose}
        aria-hidden="true"
      />

      <aside
        className={`app-sidebar ${
          open ? 'is-open' : ''
        }`}
      >
        <div className="app-sidebar__header">
          <div className="app-sidebar__brand">
            <img
              src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
              alt="Logo SMA Negeri 1 V Koto Timur"
            />

            <div>
              <strong>SIAKAD</strong>

              <span>
                SMA Negeri 1 V Koto Timur
              </span>
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

        <nav
          className="app-sidebar__nav"
          aria-label="Navigasi utama"
        >
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
                end={
                  item.path ===
                  '/app/dashboard'
                }
                className={({ isActive }) =>
                  `app-sidebar__link ${
                    isActive
                      ? 'is-active'
                      : ''
                  }`
                }
                onClick={onClose}
              >
                <span className="app-sidebar__link-icon">
                  <MenuIcon label={item.label} />
                </span>

                <span className="app-sidebar__link-label">
                  {item.label}
                </span>
              </NavLink>
            ),
          )}
        </nav>

        <div className="app-sidebar__footer">
          <strong>SIAKAD Koto Timur</strong>
          <span>
            Academic Information System
          </span>
        </div>
      </aside>
    </>
  )
}
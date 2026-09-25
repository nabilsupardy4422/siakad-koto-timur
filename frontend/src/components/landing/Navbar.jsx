import { useEffect, useState } from 'react'

const navigationItems = [
  { label: 'Beranda', href: '#beranda' },
  { label: 'Fitur', href: '#fitur' },
  { label: 'Tentang', href: '#tentang' },
  { label: 'FAQ', href: '#faq' },
]

function Navbar() {
  const [isMenuOpen, setIsMenuOpen] = useState(false)

  const closeMenu = () => {
    setIsMenuOpen(false)
  }

  useEffect(() => {
    if (!isMenuOpen) {
      document.body.style.overflow = ''
      return undefined
    }

    document.body.style.overflow = 'hidden'

    const handleEscape = (event) => {
      if (event.key === 'Escape') {
        closeMenu()
      }
    }

    document.addEventListener('keydown', handleEscape)

    return () => {
      document.body.style.overflow = ''
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isMenuOpen])

  return (
    <header className="site-header">
      <nav
        className="site-navbar"
        aria-label="Navigasi utama"
      >
        <a
          className="brand"
          href="#beranda"
          onClick={closeMenu}
        >
          <img
            className="brand-logo"
            src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
            alt="Logo SMA Negeri 1 V Koto Timur"
          />

          <span className="brand-copy">
            <strong>SIAKAD</strong>
            <span>Koto Timur</span>
          </span>
        </a>

        <div className="desktop-navigation">
          {navigationItems.map((item) => (
            <a
              key={item.href}
              href={item.href}
            >
              {item.label}
            </a>
          ))}
        </div>

        <a
          className="navbar-login-button"
          href="/login"
        >
          Masuk
        </a>

        <button
          className={`mobile-menu-button ${
            isMenuOpen ? 'is-open' : ''
          }`}
          type="button"
          aria-label={
            isMenuOpen ? 'Tutup menu' : 'Buka menu'
          }
          aria-expanded={isMenuOpen}
          aria-controls="mobile-navigation"
          onClick={() =>
            setIsMenuOpen((current) => !current)
          }
        >
          <span />
          <span />
        </button>
      </nav>

      <div
        className={`mobile-menu-overlay ${
          isMenuOpen ? 'is-visible' : ''
        }`}
        aria-hidden={!isMenuOpen}
        onClick={closeMenu}
      />

      <aside
        id="mobile-navigation"
        className={`mobile-navigation ${
          isMenuOpen ? 'is-open' : ''
        }`}
        aria-hidden={!isMenuOpen}
      >
        <div className="mobile-navigation-header">
          <span>Navigasi</span>

          <button
            className="mobile-navigation-close"
            type="button"
            aria-label="Tutup menu"
            onClick={closeMenu}
          >
            ×
          </button>
        </div>

        <div className="mobile-navigation-links">
          {navigationItems.map((item, index) => (
            <a
              key={item.href}
              href={item.href}
              onClick={closeMenu}
            >
              <span>
                {String(index + 1).padStart(2, '0')}
              </span>

              {item.label}
            </a>
          ))}
        </div>

        <div className="mobile-navigation-footer">
          <a
            className="mobile-login-button"
            href="/login"
            onClick={closeMenu}
          >
            Masuk ke SIAKAD
            <span aria-hidden="true">→</span>
          </a>

          <p>
            Sistem Informasi Akademik
            <br />
            SMA Negeri 1 V Koto Timur
          </p>
        </div>
      </aside>
    </header>
  )
}

export default Navbar
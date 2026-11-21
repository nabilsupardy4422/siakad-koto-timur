import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import './Navbar.css'

// Tetap menggunakan anchor publik yang ada pada landing page saat ini.
const navigationItems = [
  { label: 'Beranda', id: 'beranda' },
  { label: 'Fitur SIAKAD', id: 'fitur' },
  { label: 'Tentang', id: 'tentang' },
  { label: 'FAQ', id: 'faq' },
]

const getFocusableElements = (container) => {
  if (!container) return []
  return Array.from(
    container.querySelectorAll('a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])'),
  ).filter((element) => !element.hasAttribute('disabled') && !element.inert)
}

function Navbar() {
  const [isMenuOpen, setIsMenuOpen] = useState(false)
  const [isScrolled, setIsScrolled] = useState(false)
  const [isHidden, setIsHidden] = useState(false)
  const [activeSection, setActiveSection] = useState('beranda')

  const navRef = useRef(null)
  const menuButtonRef = useRef(null)
  const menuCloseRef = useRef(null)
  const menuPanelRef = useRef(null)
  const menuOpenRef = useRef(false)

  const closeMenu = useCallback((returnFocus = false) => {
    menuOpenRef.current = false
    setIsMenuOpen(false)
    if (returnFocus) requestAnimationFrame(() => menuButtonRef.current?.focus())
  }, [])

  const openMenu = () => {
    menuOpenRef.current = true
    setIsHidden(false)
    setIsMenuOpen(true)
  }

  const goToSection = (id) => {
    setActiveSection(id)
    closeMenu()
    // Saat drawer mobile ditutup, jangan tinggalkan fokus di elemen tersembunyi.
    requestAnimationFrame(() => {
      const section = document.getElementById(id)
      if (!section) return
      section.setAttribute('tabindex', '-1')
      section.focus({ preventScroll: true })
    })
  }

  useEffect(() => {
    const desktop = window.matchMedia('(min-width: 961px)')
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)')
    let frame = 0
    let lastY = Math.max(0, window.scrollY)
    let lastTime = performance.now()
    let downwardTravel = 0

    const readSections = () => {
      let current = 'beranda'
      for (const item of navigationItems) {
        const section = document.getElementById(item.id)
        if (section && section.getBoundingClientRect().top <= 168) current = item.id
      }
      setActiveSection((prev) => (prev === current ? prev : current))
    }

    const update = () => {
      frame = 0
      const y = Math.max(0, window.scrollY)
      const now = performance.now()
      const delta = y - lastY
      const elapsed = Math.max(16, now - lastTime)
      const velocity = Math.abs(delta) / elapsed

      setIsScrolled((prev) => (prev !== (y > 24) ? y > 24 : prev))
      readSections()

      // Navbar tetap tampil dekat bagian atas, pada mobile, ketika drawer
      // terbuka, ketika keyboard fokus, dan ketika reduced-motion aktif.
      const mustStayVisible = y < 220 || !desktop.matches || reducedMotion.matches ||
        menuOpenRef.current || (navRef.current?.contains(document.activeElement) &&
          document.activeElement?.matches(':focus-visible'))

      if (mustStayVisible) {
        setIsHidden(false)
        downwardTravel = 0
      } else if (delta < -5) {
        // Begitu pengguna scroll naik, akses navigasi dipulihkan.
        setIsHidden(false)
        downwardTravel = 0
      } else if (delta > 4) {
        downwardTravel += delta
        // Scroll turun harus cukup jelas sebelum navbar disembunyikan.
        if (downwardTravel >= 58 && velocity >= 0.18 && y > 280) {
          setIsHidden(true)
          downwardTravel = 0
        }
      }

      lastY = y
      lastTime = now
    }

    const onScroll = () => {
      if (frame) return
      frame = requestAnimationFrame(update)
    }

    const onLayoutChange = () => {
      setIsHidden(false)
      downwardTravel = 0
      onScroll()
    }

    const onFocus = () => setIsHidden(false)
    const navElement = navRef.current
    update()
    window.addEventListener('scroll', onScroll, { passive: true })
    window.addEventListener('resize', onLayoutChange, { passive: true })
    desktop.addEventListener('change', onLayoutChange)
    reducedMotion.addEventListener('change', onLayoutChange)
    navElement?.addEventListener('focusin', onFocus)

    return () => {
      if (frame) cancelAnimationFrame(frame)
      window.removeEventListener('scroll', onScroll)
      window.removeEventListener('resize', onLayoutChange)
      desktop.removeEventListener('change', onLayoutChange)
      reducedMotion.removeEventListener('change', onLayoutChange)
      navElement?.removeEventListener('focusin', onFocus)
    }
  }, [])

  useEffect(() => {
    if (!isMenuOpen) return undefined

    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    setIsHidden(false)
    menuCloseRef.current?.focus()

    const handleKeyDown = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault()
        closeMenu(true)
        return
      }
      if (event.key !== 'Tab') return
      const elements = getFocusableElements(menuPanelRef.current)
      if (!elements.length) return
      const first = elements[0]
      const last = elements[elements.length - 1]
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
      }
    }

    const desktop = window.matchMedia('(min-width: 961px)')
    const handleDesktop = (event) => {
      if (event.matches) closeMenu(false)
    }

    document.addEventListener('keydown', handleKeyDown)
    desktop.addEventListener('change', handleDesktop)
    return () => {
      document.body.style.overflow = previousOverflow
      document.removeEventListener('keydown', handleKeyDown)
      desktop.removeEventListener('change', handleDesktop)
    }
  }, [isMenuOpen, closeMenu])

  return (
    <header className={`site-header${isScrolled ? ' is-scrolled' : ''}${isHidden ? ' is-hidden' : ''}`}>
      <nav ref={navRef} className="site-navbar" aria-label="Navigasi utama">
        <a className="brand" href="#beranda" aria-label="SMA Negeri 1 V Koto Timur — Beranda" onClick={() => closeMenu()}>
          <span className="brand-mark">
            <img
              className="brand-logo"
              src="/images/branding/logo-sman1-v-koto-timur-removebg-preview.png"
              alt=""
              width="44"
              height="44"
            />
          </span>
          <span className="brand-copy">
            <strong>SMA Negeri 1 <span>V Koto Timur</span></strong>
            <small>Sistem Informasi Akademik</small>
          </span>
        </a>

        <div className="desktop-navigation" aria-label="Bagian halaman">
          {navigationItems.map((item) => (
            <a
              key={item.id}
              href={`#${item.id}`}
              className={activeSection === item.id ? 'is-active' : undefined}
              aria-current={activeSection === item.id ? 'location' : undefined}
              onClick={() => setActiveSection(item.id)}
            >
              {item.label}
            </a>
          ))}
        </div>

        <Link className="navbar-login-button" to="/login">
          <span>Masuk SIAKAD</span>
          <svg aria-hidden="true" viewBox="0 0 24 24" width="18" height="18" fill="none">
            <path d="M5 12h14m-6-6 6 6-6 6" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
        </Link>

        <button
          ref={menuButtonRef}
          className={`mobile-menu-button${isMenuOpen ? ' is-open' : ''}`}
          type="button"
          aria-label={isMenuOpen ? 'Tutup menu navigasi' : 'Buka menu navigasi'}
          aria-expanded={isMenuOpen}
          aria-controls="mobile-navigation"
          onClick={() => isMenuOpen ? closeMenu(true) : openMenu()}
        >
          <span className="mobile-menu-lines" aria-hidden="true"><span /><span /></span>
          <span className="mobile-menu-caption">Menu</span>
        </button>
      </nav>

      <button
        className={`mobile-menu-overlay${isMenuOpen ? ' is-visible' : ''}`}
        type="button"
        aria-label="Tutup menu navigasi"
        aria-hidden="true"
        tabIndex={-1}
        onClick={() => closeMenu(true)}
      />

      <aside
        ref={menuPanelRef}
        id="mobile-navigation"
        className={`mobile-navigation${isMenuOpen ? ' is-open' : ''}`}
        role="dialog"
        aria-label="Menu navigasi"
        aria-modal={isMenuOpen ? 'true' : undefined}
        aria-hidden={!isMenuOpen}
        inert={!isMenuOpen}
      >
        <div className="mobile-navigation-header">
          <div>
            <span className="mobile-navigation-eyebrow">SMA NEGERI 1 V KOTO TIMUR</span>
            <strong>Jelajahi halaman</strong>
          </div>
          <button
            ref={menuCloseRef}
            className="mobile-navigation-close"
            type="button"
            aria-label="Tutup menu"
            onClick={() => closeMenu(true)}
          >
            <svg aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none">
              <path d="m6 6 12 12M18 6 6 18" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
            </svg>
          </button>
        </div>

        <div className="mobile-navigation-links">
          {navigationItems.map((item, index) => (
            <a
              key={item.id}
              href={`#${item.id}`}
              className={activeSection === item.id ? 'is-active' : undefined}
              aria-current={activeSection === item.id ? 'location' : undefined}
              onClick={() => goToSection(item.id)}
            >
              <span className="mobile-navigation-number">{String(index + 1).padStart(2, '0')}</span>
              <span>{item.label}</span>
              <span className="mobile-navigation-arrow" aria-hidden="true">↗</span>
            </a>
          ))}
        </div>

        <div className="mobile-navigation-footer">
          <p>Portal akademik untuk warga SMA Negeri 1 V Koto Timur.</p>
          <Link className="mobile-login-button" to="/login" onClick={() => closeMenu()}>
            Masuk ke SIAKAD
            <span aria-hidden="true">→</span>
          </Link>
        </div>
      </aside>
    </header>
  )
}

export default Navbar
